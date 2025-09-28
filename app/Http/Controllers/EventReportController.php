<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesEvents;
use App\Models\Attendance;
use App\Models\Checkpoint;
use App\Services\Analytics\AnalyticsService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\StreamedResponse;
use function number_format;
use function ob_get_clean;
use function ob_start;

/**
 * Reporting endpoints for exporting analytics data.
 */
class EventReportController extends Controller
{
    use ResolvesEvents;

    public function __construct(private readonly AnalyticsService $analytics)
    {
    }

    /**
     * Stream attendance records as a CSV export.
     */
    public function attendanceCsv(Request $request, string $eventId): StreamedResponse|JsonResponse
    {
        $authUser = $request->user();
        $event = $this->findEventForRequest($request, $authUser, $eventId);

        if ($event === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $validated = $this->validate($request, [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'checkpoint_id' => ['nullable', 'uuid'],
        ]);

        $from = isset($validated['from']) ? CarbonImmutable::parse((string) $validated['from']) : null;
        $to = isset($validated['to']) ? CarbonImmutable::parse((string) $validated['to']) : null;
        $checkpointId = $validated['checkpoint_id'] ?? null;

        $filename = sprintf('attendance-%s.csv', $event->id);

        $callback = function () use ($event, $from, $to, $checkpointId): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, [
                'Attendance ID',
                'Ticket ID',
                'Guest ID',
                'Guest Name',
                'Guest Email',
                'Result',
                'Checkpoint',
                'Hostess',
                'Scanned At',
                'Device ID',
            ]);

            Attendance::query()
                ->with(['guest', 'ticket', 'checkpoint', 'hostess'])
                ->where('event_id', $event->id)
                ->when($checkpointId !== null, static function ($query) use ($checkpointId) {
                    $query->where('checkpoint_id', $checkpointId);
                })
                ->when($from !== null, static function ($query) use ($from) {
                    $query->where('scanned_at', '>=', $from);
                })
                ->when($to !== null, static function ($query) use ($to) {
                    $query->where('scanned_at', '<=', $to);
                })
                ->orderBy('scanned_at')
                ->orderBy('id')
                ->chunk(500, static function ($attendances) use ($handle): void {
                    foreach ($attendances as $attendance) {
                        fputcsv($handle, [
                            $attendance->id,
                            $attendance->ticket_id,
                            $attendance->guest_id,
                            $attendance->guest?->full_name,
                            $attendance->guest?->email,
                            $attendance->result,
                            $attendance->checkpoint?->name,
                            $attendance->hostess?->name,
                            optional($attendance->scanned_at)->toISOString(),
                            $attendance->device_id,
                        ]);
                    }
                });

            fclose($handle);
        };

        return response()->streamDownload($callback, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Render a summary PDF report with the main analytics tables.
     */
    public function summaryPdf(Request $request, string $eventId)
    {
        $authUser = $request->user();
        $event = $this->findEventForRequest($request, $authUser, $eventId);

        if ($event === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $validated = $this->validate($request, [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($validated['from']) ? CarbonImmutable::parse((string) $validated['from']) : null;
        $to = isset($validated['to']) ? CarbonImmutable::parse((string) $validated['to']) : null;

        $overview = $this->analytics->overview($event->id, $from, $to);
        $attendanceSeries = $this->analytics->attendanceByHour($event->id, $from, $to);
        $checkpointTotals = $this->analytics->checkpointTotals($event->id, $from, $to);
        $guestLists = $this->analytics->guestsByList($event->id);
        $funnel = $this->analytics->rsvpFunnel($event->id);

        $checkpointIds = array_values(array_filter(array_map(
            static fn (array $item): ?string => isset($item['checkpoint_id']) && $item['checkpoint_id'] !== null
                ? (string) $item['checkpoint_id']
                : null,
            Arr::get($checkpointTotals, 'checkpoints', [])
        )));

        $checkpointNames = Checkpoint::query()
            ->whereIn('id', $checkpointIds)
            ->pluck('name', 'id');

        $html = $this->renderSummaryHtml(
            $event->name,
            $from,
            $to,
            $overview,
            $funnel,
            $attendanceSeries,
            Arr::get($checkpointTotals, 'checkpoints', []),
            $checkpointNames->toArray(),
            Arr::get($guestLists, 'lists', [])
        );

        $options = new Options();
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="summary.pdf"',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overview
     * @param  array<string, int>  $funnel
     * @param  array<int, array<string, mixed>>  $attendanceSeries
     * @param  array<int, array<string, mixed>>  $checkpoints
     * @param  array<string, string>  $checkpointNames
     * @param  array<int, array<string, mixed>>  $guestLists
     */
    private function renderSummaryHtml(
        string $eventName,
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
        array $overview,
        array $funnel,
        array $attendanceSeries,
        array $checkpoints,
        array $checkpointNames,
        array $guestLists
    ): string {
        $period = [];

        if ($from !== null) {
            $period[] = sprintf('From %s', $from->toDayDateTimeString());
        }

        if ($to !== null) {
            $period[] = sprintf('To %s', $to->toDayDateTimeString());
        }

        $occupancy = $overview['occupancy_rate'] !== null
            ? number_format((float) $overview['occupancy_rate'], 2) . '%'
            : 'N/A';

        ob_start();
        ?>
        <html>
            <head>
                <meta charset="utf-8">
                <style>
                    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #1a1a1a; }
                    h1 { font-size: 20px; margin-bottom: 0; }
                    p.meta { color: #555; margin-top: 4px; }
                    table { width: 100%; border-collapse: collapse; margin-top: 16px; }
                    th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
                    th { background-color: #f5f5f5; font-weight: bold; }
                </style>
            </head>
            <body>
                <h1><?= e($eventName) ?> — Summary Report</h1>
                <?php if (! empty($period)) : ?>
                    <p class="meta"><?= e(implode(' · ', $period)) ?></p>
                <?php endif; ?>

                <h2>Overview</h2>
                <table>
                    <tr>
                        <th>Invited</th>
                        <th>Confirmed</th>
                        <th>Valid Scans</th>
                        <th>Duplicate Scans</th>
                        <th>Unique Attendees</th>
                        <th>Occupancy</th>
                    </tr>
                    <tr>
                        <td><?= e((string) Arr::get($overview, 'invited', 0)) ?></td>
                        <td><?= e((string) Arr::get($overview, 'confirmed', 0)) ?></td>
                        <td><?= e((string) Arr::get($overview, 'attendances', 0)) ?></td>
                        <td><?= e((string) Arr::get($overview, 'duplicates', 0)) ?></td>
                        <td><?= e((string) Arr::get($overview, 'unique_attendees', 0)) ?></td>
                        <td><?= e($occupancy) ?></td>
                    </tr>
                </table>

                <h2>RSVP Funnel</h2>
                <table>
                    <tr>
                        <th>Invited</th>
                        <th>Confirmed</th>
                        <th>Declined</th>
                    </tr>
                    <tr>
                        <td><?= e((string) Arr::get($funnel, 'invited', 0)) ?></td>
                        <td><?= e((string) Arr::get($funnel, 'confirmed', 0)) ?></td>
                        <td><?= e((string) Arr::get($funnel, 'declined', 0)) ?></td>
                    </tr>
                </table>

                <h2>Attendance by Hour</h2>
                <table>
                    <tr>
                        <th>Hour</th>
                        <th>Valid</th>
                        <th>Duplicate</th>
                        <th>Unique</th>
                    </tr>
                    <?php foreach ($attendanceSeries as $row) : ?>
                        <tr>
                            <td><?= e((string) Arr::get($row, 'date_hour', '')) ?></td>
                            <td><?= e((string) Arr::get($row, 'scans_valid', 0)) ?></td>
                            <td><?= e((string) Arr::get($row, 'scans_duplicate', 0)) ?></td>
                            <td><?= e((string) Arr::get($row, 'unique_guests_in', 0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <h2>Checkpoint Totals</h2>
                <table>
                    <tr>
                        <th>Checkpoint</th>
                        <th>Valid</th>
                        <th>Duplicate</th>
                        <th>Invalid</th>
                    </tr>
                    <?php foreach ($checkpoints as $checkpoint) :
                        $id = Arr::get($checkpoint, 'checkpoint_id');
                        $name = $id !== null && isset($checkpointNames[$id]) ? $checkpointNames[$id] : 'Unassigned';
                    ?>
                        <tr>
                            <td><?= e((string) $name) ?></td>
                            <td><?= e((string) Arr::get($checkpoint, 'valid', 0)) ?></td>
                            <td><?= e((string) Arr::get($checkpoint, 'duplicate', 0)) ?></td>
                            <td><?= e((string) Arr::get($checkpoint, 'invalid', 0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <h2>Guests by List</h2>
                <table>
                    <tr>
                        <th>List</th>
                        <th>Guests</th>
                    </tr>
                    <?php foreach ($guestLists as $list) : ?>
                        <tr>
                            <td><?= e((string) Arr::get($list, 'guest_list_name', 'Unassigned')) ?></td>
                            <td><?= e((string) Arr::get($list, 'guests', 0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </body>
        </html>
        <?php

        return (string) ob_get_clean();
    }
}

