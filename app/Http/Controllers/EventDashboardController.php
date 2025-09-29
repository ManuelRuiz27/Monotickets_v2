<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesEvents;
use App\Models\Checkpoint;
use App\Services\SnapshotService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Dashboard analytics endpoints for events.
 */
class EventDashboardController extends Controller
{
    use ResolvesEvents;

    /**
     * Return the main overview metrics for the dashboard.
     */
    public function overview(Request $request, SnapshotService $snapshots, string $event_id): JsonResponse
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

        $params = $this->buildSnapshotParams($event->tenant_id, $event->id, $validated);

        $metrics = $snapshots->compute('overview', $params);

        return response()->json([
            'data' => $metrics,
        ]);
    }

    /**
     * Return attendance metrics grouped by hour.
     */
    public function attendanceByHour(Request $request, SnapshotService $snapshots, string $event_id): JsonResponse
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

        $params = $this->buildSnapshotParams($event->tenant_id, $event->id, $validated);

        $series = array_map(
            static function (array $entry): array {
                return [
                    'hour' => $entry['date_hour'] ?? null,
                    'valid' => (int) Arr::get($entry, 'scans_valid', 0),
                    'duplicate' => (int) Arr::get($entry, 'scans_duplicate', 0),
                    'unique' => (int) Arr::get($entry, 'unique_guests_in', 0),
                ];
            },
            $snapshots->compute('attendance_by_hour', $params)
        );

        return response()->json([
            'data' => $series,
        ]);
    }

    /**
     * Return checkpoint totals for the dashboard.
     */
    public function checkpointTotals(Request $request, SnapshotService $snapshots, string $event_id): JsonResponse
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

        $params = $this->buildSnapshotParams($event->tenant_id, $event->id, $validated);

        $payload = $snapshots->compute('checkpoint_totals', $params);
        $checkpoints = Arr::get($payload, 'checkpoints', []);

        $checkpointIds = array_values(array_filter(array_map(
            static fn (array $item): ?string => isset($item['checkpoint_id']) && $item['checkpoint_id'] !== null
                ? (string) $item['checkpoint_id']
                : null,
            $checkpoints
        )));

        $checkpointNames = Checkpoint::query()
            ->whereIn('id', $checkpointIds)
            ->pluck('name', 'id');

        $data = array_map(
            static function (array $item) use ($checkpointNames): array {
                $checkpointId = isset($item['checkpoint_id']) && $item['checkpoint_id'] !== null
                    ? (string) $item['checkpoint_id']
                    : null;

                return [
                    'checkpoint_id' => $checkpointId,
                    'name' => $checkpointId !== null ? ($checkpointNames[$checkpointId] ?? null) : null,
                    'valid' => (int) Arr::get($item, 'valid', 0),
                    'duplicate' => (int) Arr::get($item, 'duplicate', 0),
                ];
            },
            $checkpoints
        );

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * Return RSVP funnel totals for the dashboard.
     */
    public function rsvpFunnel(Request $request, SnapshotService $snapshots, string $event_id): JsonResponse
    {
        $eventId = $event_id;
        $authUser = $request->user();
        $event = $this->findEventForRequest($request, $authUser, $eventId);

        if ($event === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $params = $this->buildSnapshotParams($event->tenant_id, $event->id, []);

        return response()->json([
            'data' => $snapshots->compute('rsvp_funnel', $params),
        ]);
    }

    /**
     * Return guests grouped by list for the dashboard.
     */
    public function guestsByList(Request $request, SnapshotService $snapshots, string $event_id): JsonResponse
    {
        $eventId = $event_id;
        $authUser = $request->user();
        $event = $this->findEventForRequest($request, $authUser, $eventId);

        if ($event === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $params = $this->buildSnapshotParams($event->tenant_id, $event->id, []);
        $payload = $snapshots->compute('guests_by_list', $params);

        $data = array_map(
            static function (array $item): array {
                return [
                    'list' => $item['guest_list_name'] ?? null,
                    'count' => (int) Arr::get($item, 'guests', 0),
                ];
            },
            Arr::get($payload, 'lists', [])
        );

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function buildSnapshotParams(string $tenantId, string $eventId, array $validated): array
    {
        $params = [
            'tenant_id' => $tenantId,
            'event_id' => $eventId,
        ];

        if (isset($validated['from'])) {
            $params['from'] = CarbonImmutable::parse((string) $validated['from']);
        }

        if (isset($validated['to'])) {
            $params['to'] = CarbonImmutable::parse((string) $validated['to']);
        }

        return $params;
    }
}

