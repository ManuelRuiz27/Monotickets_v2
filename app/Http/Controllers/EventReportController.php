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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    private const CSV_CHUNK_SIZE = 5000;

    private const MAX_RANGE_DAYS = 7;

    private const DEFAULT_RANGE_HOURS = 48;

    private const PDF_PAGE_THRESHOLD = 6;

    public function __construct(private readonly AnalyticsService $analytics)
    {
    }

    /**
     * Stream attendance records as a CSV export.
     */
    public function attendanceCsv(Request $request, string $event_id): StreamedResponse|JsonResponse
    {
        $eventId = $event_id;
        $authUser = $request->user();
        $event = $this->findEventForRequest($request, $authUser, $eventId);

        if ($event === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $event->loadMissing(['tenant.latestSubscription.plan']);

        $validated = $this->validate($request, [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'checkpoint_id' => ['nullable', 'uuid'],
        ]);

        $range = $this->resolveDateRange(
            isset($validated['from']) ? CarbonImmutable::parse((string) $validated['from']) : null,
            isset($validated['to']) ? CarbonImmutable::parse((string) $validated['to']) : null
        );

        if ($range === null) {
            return ApiResponse::error(
                'INVALID_RANGE',
                sprintf('The requested period cannot exceed %d days.', self::MAX_RANGE_DAYS),
                null,
                422
            );
        }

        $generationStartedAt = microtime(true);

        [$from, $to] = $range;
        $checkpointId = $validated['checkpoint_id'] ?? null;

        $filename = sprintf('attendance-%s.csv', $event->id);

        $obfuscate = $this->shouldObfuscateSensitiveFields($event);

        $callback = function () use ($event, $from, $to, $checkpointId, $obfuscate): void {
            $startedAt = microtime(true);
            $bytesWritten = 0;
            $status = 'success';

            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                $status = 'stream_unavailable';
            } else {
                try {
                    $written = fputcsv($handle, [
                        'timestamp',
                        'checkpoint',
                        'ticket',
                        'guest',
                        'hostess',
                        'result',
                    ]);

                    if ($written === false) {
                        $status = 'write_failed';
                    } else {
                        $bytesWritten += $written;

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
                            ->chunk(self::CSV_CHUNK_SIZE, function ($attendances) use ($handle, &$bytesWritten, &$status, $obfuscate): void {
                                if ($status !== 'success') {
                                    return;
                                }

                                foreach ($attendances as $attendance) {
                                    $ticketId = $attendance->ticket_id;
                                    $guestName = $attendance->guest?->full_name;
                                    $hostessName = $attendance->hostess?->name;

                                    if ($obfuscate) {
                                        $ticketId = $this->maskIdentifier($ticketId);
                                        $guestName = $this->maskName($guestName);
                                        $hostessName = $this->maskName($hostessName);
                                    }

                                    $written = fputcsv($handle, [
                                        optional($attendance->scanned_at)->toISOString(),
                                        $attendance->checkpoint?->name,
                                        $ticketId,
                                        $guestName,
                                        $hostessName,
                                        $attendance->result,
                                    ]);

                                    if ($written === false) {
                                        $status = 'write_failed';
                                        break;
                                    }

                                    $bytesWritten += $written;
                                }
                            });
                    }
                } finally {
                    fclose($handle);
                }
            }

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            Log::info('exports.generated', [
                'type' => 'attendance_csv',
                'event_id' => (string) $event->id,
                'tenant_id' => (string) $event->tenant_id,
                'bytes' => $bytesWritten,
                'duration_ms' => $durationMs,
                'status' => $status,
                'from' => $from?->toISOString(),
                'to' => $to?->toISOString(),
                'checkpoint_id' => $checkpointId,
            ]);
        };

        return response()->streamDownload($callback, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Render a summary PDF report with the main analytics tables.
     */
    public function summaryPdf(Request $request, string $event_id)
    {
        $eventId = $event_id;
        $authUser = $request->user();
        $event = $this->findEventForRequest($request, $authUser, $eventId);

        if ($event === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $validated = $this->validate($request, [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $range = $this->resolveDateRange(
            isset($validated['from']) ? CarbonImmutable::parse((string) $validated['from']) : null,
            isset($validated['to']) ? CarbonImmutable::parse((string) $validated['to']) : null
        );

        if ($range === null) {
            return ApiResponse::error(
                'INVALID_RANGE',
                sprintf('The requested period cannot exceed %d days.', self::MAX_RANGE_DAYS),
                null,
                422
            );
        }

        [$from, $to] = $range;

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

        $pageCount = $dompdf->getCanvas()->get_page_count();

        if ($pageCount > self::PDF_PAGE_THRESHOLD) {
            $font = $dompdf->getFontMetrics()->get_font('helvetica', 'normal');
            $dompdf->getCanvas()->page_text(
                40,
                40,
                'Reporte extenso: considera exportar la lista completa en CSV.',
                $font,
                10,
                [0, 0, 0],
                0.0,
                0.0,
                0.0,
                'left',
                false
            );
        }

        $pdf = $dompdf->output();

        $durationMs = (int) round((microtime(true) - $generationStartedAt) * 1000);
        $bytes = strlen($pdf);

        Log::info('exports.generated', [
            'type' => 'summary_pdf',
            'event_id' => (string) $event->id,
            'tenant_id' => (string) $event->tenant_id,
            'bytes' => $bytes,
            'duration_ms' => $durationMs,
            'pages' => $pageCount,
            'from' => $from?->toISOString(),
            'to' => $to?->toISOString(),
        ]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="summary.pdf"',
            'X-Report-Pages' => (string) $pageCount,
            'X-Report-Suggested-Export' => $pageCount > self::PDF_PAGE_THRESHOLD ? 'csv' : 'pdf',
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
            $period[] = sprintf('Desde %s', $from->toDayDateTimeString());
        }

        if ($to !== null) {
            $period[] = sprintf('Hasta %s', $to->toDayDateTimeString());
        }

        $occupancy = $overview['occupancy_rate'] !== null
            ? number_format((float) $overview['occupancy_rate'] * 100, 2) . '%'
            : 'N/A';

        ob_start();
        ?>
        <html>
            <head>
                <meta charset="utf-8">
                <style>
                    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #1a1a1a; padding: 24px; }
                    header { border-bottom: 1px solid #e0e0e0; margin-bottom: 16px; }
                    h1 { font-size: 20px; margin: 0; }
                    h2 { font-size: 16px; margin-bottom: 8px; }
                    p.meta { color: #555; margin: 4px 0 0; }
                    section { margin-bottom: 24px; }
                    table { width: 100%; border-collapse: collapse; margin-top: 12px; }
                    th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
                    th { background-color: #f5f5f5; font-weight: bold; }
                    .totals { display: flex; gap: 16px; margin-top: 12px; }
                    .totals .card { background: #f9fafb; border: 1px solid #e5e7eb; padding: 12px 16px; border-radius: 8px; flex: 1; }
                    .totals .card strong { display: block; font-size: 12px; text-transform: uppercase; color: #4b5563; }
                    .totals .card div { font-size: 20px; margin-top: 4px; font-weight: bold; }
                    .chart-placeholder { height: 120px; border: 1px dashed #d1d5db; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #6b7280; font-size: 11px; margin-top: 12px; }
                    .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
                    .small { font-size: 11px; color: #6b7280; margin-top: 4px; }
                </style>
            </head>
            <body>
                <header>
                    <h1><?= e($eventName) ?></h1>
                    <p class="meta">Resumen operativo<?= $period !== [] ? ': ' . implode(' · ', $period) : '' ?></p>
                </header>

                <section>
                    <h2>Totales principales</h2>
                    <div class="totals">
                        <div class="card">
                            <strong>Invitados</strong>
                            <div><?= number_format((int) Arr::get($overview, 'invited', 0)) ?></div>
                            <p class="small">Registros creados</p>
                        </div>
                        <div class="card">
                            <strong>Confirmados</strong>
                            <div><?= number_format((int) Arr::get($overview, 'confirmed', 0)) ?></div>
                            <p class="small">RSVP positivos</p>
                        </div>
                        <div class="card">
                            <strong>Check-ins válidos</strong>
                            <div><?= number_format((int) Arr::get($overview, 'attendances', 0)) ?></div>
                            <p class="small">Ingresos escaneados</p>
                        </div>
                        <div class="card">
                            <strong>Ocupación</strong>
                            <div><?= $occupancy ?></div>
                            <p class="small">Porcentaje del aforo</p>
                        </div>
                    </div>
                    <div class="chart-placeholder">Gráfica de evolución de asistencias</div>
                </section>

                <section>
                    <h2>Embudo de RSVP</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Estado</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($funnel as $status => $count) : ?>
                                <tr>
                                    <td><?= e((string) Str::title(str_replace('_', ' ', (string) $status))) ?></td>
                                    <td><?= number_format((int) $count) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="chart-placeholder">Mini gráfica de distribución por estado</div>
                </section>

                <section>
                    <h2>Asistencias por hora</h2>
                    <div class="grid">
                        <div>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Hora</th>
                                        <th>Válidos</th>
                                        <th>Duplicados</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($attendanceSeries as $row) : ?>
                                        <tr>
                                            <td><?= e((string) Arr::get($row, 'date_hour', '')) ?></td>
                                            <td><?= number_format((int) Arr::get($row, 'scans_valid', 0)) ?></td>
                                            <td><?= number_format((int) Arr::get($row, 'scans_duplicate', 0)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="chart-placeholder">Sparklines de actividad por hora</div>
                    </div>
                </section>

                <section>
                    <h2>Totales por checkpoint</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Checkpoint</th>
                                <th>Válidos</th>
                                <th>Duplicados</th>
                                <th>Inválidos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($checkpoints as $row) :
                                $id = Arr::get($row, 'checkpoint_id');
                                $name = $id !== null && isset($checkpointNames[(string) $id]) ? $checkpointNames[(string) $id] : 'Sin asignar';
                            ?>
                                <tr>
                                    <td><?= e((string) $name) ?></td>
                                    <td><?= number_format((int) Arr::get($row, 'valid', 0)) ?></td>
                                    <td><?= number_format((int) Arr::get($row, 'duplicate', 0)) ?></td>
                                    <td><?= number_format((int) Arr::get($row, 'invalid', 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>

                <section>
                    <h2>Listas de invitados</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Lista</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($guestLists as $row) : ?>
                                <tr>
                                    <td><?= e((string) (Arr::get($row, 'guest_list_name') ?? 'Sin lista')) ?></td>
                                    <td><?= number_format((int) Arr::get($row, 'guests', 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            </body>
        </html>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Resolve the requested date range enforcing defaults and limits.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}|null
     */
    private function resolveDateRange(?CarbonImmutable $from, ?CarbonImmutable $to): ?array
    {
        if ($from === null && $to === null) {
            $to = CarbonImmutable::now();
            $from = $to->subHours(self::DEFAULT_RANGE_HOURS);
        } elseif ($from !== null && $to === null) {
            $limit = $from->addDays(self::MAX_RANGE_DAYS);
            $to = CarbonImmutable::now();

            if ($to->greaterThan($limit)) {
                $to = $limit;
            }
        } elseif ($from === null && $to !== null) {
            $from = $to->subDays(self::MAX_RANGE_DAYS);
        }

        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            return null;
        }

        if ($from !== null && $to !== null && $from->addDays(self::MAX_RANGE_DAYS)->lessThan($to)) {
            return null;
        }

        return [$from, $to];
    }

    private function shouldObfuscateSensitiveFields(Event $event): bool
    {
        $tenant = $event->tenant;

        if ($tenant === null) {
            return false;
        }

        $settings = $tenant->settings_json;

        if (is_array($settings)) {
            $override = Arr::get($settings, 'privacy.allow_pii_exports');

            if (is_bool($override)) {
                return ! $override;
            }
        }

        $subscription = $tenant->latestSubscription;

        if ($subscription === null) {
            $subscription = $tenant->activeSubscription();
        }

        $plan = $subscription?->plan;

        if ($plan === null) {
            return false;
        }

        $features = is_array($plan->features_json) ? $plan->features_json : [];

        return ! (bool) Arr::get($features, 'exports.pii', true);
    }

    private function maskIdentifier(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return $value;
        }

        $length = mb_strlen($trimmed);

        if ($length <= 4) {
            return str_repeat('•', $length);
        }

        return Str::substr($trimmed, 0, 2) . str_repeat('•', $length - 4) . Str::substr($trimmed, -2);
    }

    private function maskName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return $value;
        }

        $parts = preg_split('/\s+/', $trimmed) ?: [];

        if ($parts === []) {
            return Str::upper(Str::substr($trimmed, 0, 1)) . '.';
        }

        $initials = array_map(static function (string $part): string {
            return Str::upper(Str::substr($part, 0, 1)) . '.';
        }, $parts);

        return implode(' ', $initials);
    }
}
