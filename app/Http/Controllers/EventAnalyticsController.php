<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesEvents;
use App\Models\Attendance;
use App\Models\Checkpoint;
use App\Services\Analytics\AnalyticsService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Provide consolidated analytics datasets for events.
 */
class EventAnalyticsController extends Controller
{
    use ResolvesEvents;

    public function show(Request $request, AnalyticsService $analytics, string $event_id): JsonResponse
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
            'hour_page' => ['nullable', 'integer', 'min:1'],
            'hour_per_page' => ['nullable', 'integer', 'min:1', 'max:168'],
            'checkpoint_page' => ['nullable', 'integer', 'min:1'],
            'checkpoint_per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'duplicates_page' => ['nullable', 'integer', 'min:1'],
            'duplicates_per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'errors_page' => ['nullable', 'integer', 'min:1'],
            'errors_per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;

        $hourSeries = array_map(
            static function (array $entry): array {
                return [
                    'hour' => $entry['date_hour'] ?? null,
                    'valid' => (int) Arr::get($entry, 'scans_valid', 0),
                    'duplicate' => (int) Arr::get($entry, 'scans_duplicate', 0),
                    'unique' => (int) Arr::get($entry, 'unique_guests_in', 0),
                ];
            },
            $analytics->attendanceByHour($event->id, $from, $to)
        );

        $hourPaginator = $this->paginateArray(
            $hourSeries,
            (int) ($validated['hour_per_page'] ?? 24),
            (int) ($validated['hour_page'] ?? 1)
        );

        $checkpointMetrics = $analytics->checkpointTotals($event->id, $from, $to);
        $checkpointData = Arr::get($checkpointMetrics, 'checkpoints', []);

        $checkpointIds = array_values(array_filter(array_map(
            static function (array $row): ?string {
                return isset($row['checkpoint_id']) && $row['checkpoint_id'] !== null
                    ? (string) $row['checkpoint_id']
                    : null;
            },
            $checkpointData
        )));

        $checkpointNames = $checkpointIds === []
            ? []
            : Checkpoint::query()
                ->whereIn('id', $checkpointIds)
                ->pluck('name', 'id')
                ->all();

        $checkpointSeries = array_map(
            static function (array $item) use ($checkpointNames): array {
                $checkpointId = isset($item['checkpoint_id']) && $item['checkpoint_id'] !== null
                    ? (string) $item['checkpoint_id']
                    : null;

                return [
                    'checkpoint_id' => $checkpointId,
                    'name' => $checkpointId !== null ? ($checkpointNames[$checkpointId] ?? null) : null,
                    'valid' => (int) Arr::get($item, 'valid', 0),
                    'duplicate' => (int) Arr::get($item, 'duplicate', 0),
                    'invalid' => (int) Arr::get($item, 'invalid', 0),
                ];
            },
            $checkpointData
        );

        $checkpointPaginator = $this->paginateArray(
            $checkpointSeries,
            (int) ($validated['checkpoint_per_page'] ?? 10),
            (int) ($validated['checkpoint_page'] ?? 1)
        );

        $duplicatesPaginator = $this->paginateQuery(
            Attendance::query()
                ->select([
                    'attendances.ticket_id as ticket_id',
                    DB::raw('count(*) as occurrences'),
                    DB::raw('max(attendances.scanned_at) as last_scanned_at'),
                    DB::raw('max(guests.full_name) as guest_name'),
                    DB::raw('max(qr_codes.display_code) as qr_code'),
                ])
                ->join('tickets', 'tickets.id', '=', 'attendances.ticket_id')
                ->leftJoin('guests', 'guests.id', '=', 'tickets.guest_id')
                ->leftJoin('qrs as qr_codes', function ($join): void {
                    $join->on('qr_codes.ticket_id', '=', 'attendances.ticket_id')
                        ->whereNull('qr_codes.deleted_at');
                })
                ->where('attendances.event_id', $event->id)
                ->where('attendances.result', 'duplicate')
                ->whereNull('attendances.deleted_at')
                ->whereNull('tickets.deleted_at')
                ->groupBy('attendances.ticket_id')
                ->orderByDesc('occurrences')
                ->orderByDesc(DB::raw('last_scanned_at')),
            (int) ($validated['duplicates_per_page'] ?? 10),
            (int) ($validated['duplicates_page'] ?? 1),
            'duplicates_page'
        );

        $duplicatesData = $duplicatesPaginator->getCollection()->map(
            static function ($row): array {
                $lastScanned = $row->last_scanned_at !== null
                    ? CarbonImmutable::parse($row->last_scanned_at)->toIso8601String()
                    : null;

                return [
                    'ticket_id' => $row->ticket_id !== null ? (string) $row->ticket_id : null,
                    'qr_code' => $row->qr_code !== null ? (string) $row->qr_code : null,
                    'guest_name' => $row->guest_name !== null ? (string) $row->guest_name : null,
                    'occurrences' => (int) $row->occurrences,
                    'last_scanned_at' => $lastScanned,
                ];
            }
        );

        $duplicatesPaginator->setCollection($duplicatesData);

        $errorsPaginator = $this->paginateQuery(
            Attendance::query()
                ->select([
                    'attendances.ticket_id as ticket_id',
                    'attendances.result as result',
                    DB::raw('count(*) as occurrences'),
                    DB::raw('max(attendances.scanned_at) as last_scanned_at'),
                    DB::raw('max(guests.full_name) as guest_name'),
                    DB::raw('max(qr_codes.display_code) as qr_code'),
                ])
                ->join('tickets', 'tickets.id', '=', 'attendances.ticket_id')
                ->leftJoin('guests', 'guests.id', '=', 'tickets.guest_id')
                ->leftJoin('qrs as qr_codes', function ($join): void {
                    $join->on('qr_codes.ticket_id', '=', 'attendances.ticket_id')
                        ->whereNull('qr_codes.deleted_at');
                })
                ->where('attendances.event_id', $event->id)
                ->whereIn('attendances.result', ['invalid', 'revoked', 'expired'])
                ->whereNull('attendances.deleted_at')
                ->whereNull('tickets.deleted_at')
                ->groupBy('attendances.ticket_id', 'attendances.result')
                ->orderByDesc('occurrences')
                ->orderByDesc(DB::raw('last_scanned_at')),
            (int) ($validated['errors_per_page'] ?? 10),
            (int) ($validated['errors_page'] ?? 1),
            'errors_page'
        );

        $errorsData = $errorsPaginator->getCollection()->map(
            static function ($row): array {
                $lastScanned = $row->last_scanned_at !== null
                    ? CarbonImmutable::parse($row->last_scanned_at)->toIso8601String()
                    : null;

                return [
                    'ticket_id' => $row->ticket_id !== null ? (string) $row->ticket_id : null,
                    'result' => $row->result,
                    'qr_code' => $row->qr_code !== null ? (string) $row->qr_code : null,
                    'guest_name' => $row->guest_name !== null ? (string) $row->guest_name : null,
                    'occurrences' => (int) $row->occurrences,
                    'last_scanned_at' => $lastScanned,
                ];
            }
        );

        $errorsPaginator->setCollection($errorsData);

        return response()->json([
            'data' => [
                'hourly' => $this->formatPaginator($hourPaginator),
                'checkpoints' => array_merge(
                    $this->formatPaginator($checkpointPaginator),
                    [
                        'totals' => [
                            'valid' => (int) Arr::get($checkpointMetrics, 'totals.valid', 0),
                            'duplicate' => (int) Arr::get($checkpointMetrics, 'totals.duplicate', 0),
                            'invalid' => (int) Arr::get($checkpointMetrics, 'totals.invalid', 0),
                        ],
                    ]
                ),
                'duplicates' => $this->formatPaginator($duplicatesPaginator),
                'errors' => $this->formatPaginator($errorsPaginator),
            ],
        ]);
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function paginateArray(array $items, int $perPage, int $page): LengthAwarePaginator
    {
        $perPage = max(1, $perPage);
        $page = max(1, $page);

        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($items, $offset, $perPage);

        return new LengthAwarePaginator($slice, $total, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
        ]);
    }

    private function paginateQuery($query, int $perPage, int $page, string $pageName): LengthAwarePaginator
    {
        $perPage = max(1, $perPage);
        $page = max(1, $page);

        return $query->paginate($perPage, ['*'], $pageName, $page);
    }

    private function formatPaginator(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ];
    }
}
