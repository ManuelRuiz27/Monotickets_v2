<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithTenants;
use App\Http\Requests\Checkpoint\CheckpointIndexRequest;
use App\Http\Requests\Checkpoint\CheckpointStoreRequest;
use App\Http\Requests\Checkpoint\CheckpointUpdateRequest;
use App\Models\Checkpoint;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use App\Support\ApiResponse;
use App\Support\Audit\RecordsAuditLogs;
use App\Support\Logging\StructuredLogging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Manage checkpoints associated with event venues.
 */
class CheckpointController extends Controller
{
    use InteractsWithTenants;
    use RecordsAuditLogs;
    use StructuredLogging;

    /**
     * Display a paginated listing of checkpoints for the venue.
     */
    public function index(CheckpointIndexRequest $request, string $event_id, string $venue_id): JsonResponse
    {
        $eventId = $event_id;
        $venueId = $venue_id;
        /** @var User $authUser */
        $authUser = $request->user();
        $event = $this->locateEvent($request, $authUser, $eventId);

        if ($event === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $venue = $this->locateVenue($event, $venueId);

        if ($venue === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 15);

        /** @var LengthAwarePaginator $paginator */
        $paginator = Checkpoint::query()
            ->where('event_id', $event->id)
            ->where('venue_id', $venue->id)
            ->orderBy('name')
            ->paginate($perPage);

        $paginator->getCollection()->transform(fn (Checkpoint $checkpoint): array => $this->formatCheckpoint($checkpoint));

        return ApiResponse::paginate($paginator->items(), [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'total_pages' => $paginator->lastPage(),
        ]);
    }

    /**
     * Store a newly created checkpoint for the venue.
     */
    public function store(CheckpointStoreRequest $request, string $event_id, string $venue_id): JsonResponse
    {
        $eventId = $event_id;
        $venueId = $venue_id;
        /** @var User $authUser */
        $authUser = $request->user();
        $event = $this->locateEvent($request, $authUser, $eventId);

        if ($event === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $venue = $this->locateVenue($event, $venueId);

        if ($venue === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $validated = $request->validated();

        $checkpoint = new Checkpoint();
        $checkpoint->fill($validated);
        $checkpoint->event_id = $event->id;
        $checkpoint->venue_id = $venue->id;
        $checkpoint->save();
        $checkpoint->refresh();

        $checkpointSnapshot = $this->checkpointAuditSnapshot($checkpoint);

        $this->recordAuditLog($authUser, $request, 'checkpoint', $checkpoint->id, 'created', [
            'after' => $checkpointSnapshot,
        ], $event->tenant_id);

        $this->logEntityLifecycle(
            $request,
            $authUser,
            'checkpoint',
            (string) $checkpoint->id,
            'created',
            (string) $event->tenant_id,
            [
                'event_id' => $event->id,
                'venue_id' => $venue->id,
                'after' => $checkpointSnapshot,
            ]
        );

        $this->logLifecycleMetric(
            $request,
            $authUser,
            'checkpoints_created',
            'checkpoint',
            (string) $checkpoint->id,
            (string) $event->tenant_id,
            [
                'event_id' => $event->id,
                'venue_id' => $venue->id,
            ]
        );

        return response()->json([
            'data' => $this->formatCheckpoint($checkpoint),
        ], 201);
    }

    /**
     * Display the specified checkpoint.
     */
    public function show(Request $request, string $event_id, string $venue_id, string $checkpoint_id): JsonResponse
    {
        $eventId = $event_id;
        $venueId = $venue_id;
        $checkpointId = $checkpoint_id;
        /** @var User $authUser */
        $authUser = $request->user();
        $event = $this->locateEvent($request, $authUser, $eventId);

        if ($event === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $venue = $this->locateVenue($event, $venueId);

        if ($venue === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $checkpoint = $this->locateCheckpoint($event, $venue, $checkpointId);

        if ($checkpoint === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        return response()->json([
            'data' => $this->formatCheckpoint($checkpoint),
        ]);
    }

    /**
     * Update the specified checkpoint.
     */
    public function update(CheckpointUpdateRequest $request, string $event_id, string $venue_id, string $checkpoint_id): JsonResponse
    {
        $eventId = $event_id;
        $venueId = $venue_id;
        $checkpointId = $checkpoint_id;
        /** @var User $authUser */
        $authUser = $request->user();
        $event = $this->locateEvent($request, $authUser, $eventId);

        if ($event === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $venue = $this->locateVenue($event, $venueId);

        if ($venue === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $checkpoint = $this->locateCheckpoint($event, $venue, $checkpointId);

        if ($checkpoint === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $validated = $request->validated();

        if ($validated !== []) {
            $originalSnapshot = $this->checkpointAuditSnapshot($checkpoint);

            $checkpoint->fill($validated);
            $checkpoint->save();
            $checkpoint->refresh();

            $updatedSnapshot = $this->checkpointAuditSnapshot($checkpoint);
            $changes = $this->calculateDifferences($originalSnapshot, $updatedSnapshot);

            if ($changes !== []) {
                $this->recordAuditLog($authUser, $request, 'checkpoint', $checkpoint->id, 'updated', [
                    'changes' => $changes,
                ], $event->tenant_id);

                $this->logEntityLifecycle(
                    $request,
                    $authUser,
                    'checkpoint',
                    (string) $checkpoint->id,
                    'updated',
                    (string) $event->tenant_id,
                    [
                        'event_id' => $event->id,
                        'venue_id' => $venue->id,
                        'changes' => $changes,
                    ]
                );
            }
        }

        return response()->json([
            'data' => $this->formatCheckpoint($checkpoint),
        ]);
    }

    /**
     * Remove the specified checkpoint from storage.
     */
    public function destroy(Request $request, string $event_id, string $venue_id, string $checkpoint_id): JsonResponse
    {
        $eventId = $event_id;
        $venueId = $venue_id;
        $checkpointId = $checkpoint_id;
        /** @var User $authUser */
        $authUser = $request->user();
        $event = $this->locateEvent($request, $authUser, $eventId);

        if ($event === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $venue = $this->locateVenue($event, $venueId);

        if ($venue === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $checkpoint = $this->locateCheckpoint($event, $venue, $checkpointId);

        if ($checkpoint === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $snapshot = $this->checkpointAuditSnapshot($checkpoint);

        $checkpoint->delete();

        $this->recordAuditLog($authUser, $request, 'checkpoint', $checkpoint->id, 'deleted', [
            'before' => $snapshot,
        ], $event->tenant_id);

        $this->logEntityLifecycle(
            $request,
            $authUser,
            'checkpoint',
            (string) $checkpoint->id,
            'deleted',
            (string) $event->tenant_id,
            [
                'event_id' => $event->id,
                'venue_id' => $venue->id,
                'before' => $snapshot,
            ]
        );

        return response()->json(null, 204);
    }

    /**
     * Locate an event constrained by tenant access rules.
     */
    private function locateEvent(Request $request, User $authUser, string $event_id): ?Event
    {
        $eventId = $event_id;
        $query = Event::query()->whereKey($eventId);
        $tenantId = $this->resolveTenantContext($request, $authUser);

        if ($this->isSuperAdmin($authUser)) {
            if ($tenantId !== null) {
                $query->where('tenant_id', $tenantId);
            }
        } else {
            if ($tenantId === null) {
                $this->throwValidationException([
                    'tenant_id' => ['Unable to determine tenant context.'],
                ]);
            }

            $query->where('tenant_id', $tenantId);
        }

        return $query->first();
    }

    /**
     * Locate a venue belonging to the specified event.
     */
    private function locateVenue(Event $event, string $venueId): ?Venue
    {
        return Venue::query()
            ->where('event_id', $event->id)
            ->whereKey($venueId)
            ->first();
    }

    /**
     * Locate a checkpoint ensuring it belongs to the event and venue.
     */
    private function locateCheckpoint(Event $event, Venue $venue, string $checkpointId): ?Checkpoint
    {
        return Checkpoint::query()
            ->where('event_id', $event->id)
            ->where('venue_id', $venue->id)
            ->whereKey($checkpointId)
            ->first();
    }

    /**
     * Format a checkpoint resource for API responses.
     *
     * @return array<string, mixed>
     */
    private function formatCheckpoint(Checkpoint $checkpoint): array
    {
        return [
            'id' => $checkpoint->id,
            'event_id' => $checkpoint->event_id,
            'venue_id' => $checkpoint->venue_id,
            'name' => $checkpoint->name,
            'description' => $checkpoint->description,
            'created_at' => optional($checkpoint->created_at)->toISOString(),
            'updated_at' => optional($checkpoint->updated_at)->toISOString(),
        ];
    }

    /**
     * Build a normalized snapshot for checkpoint audit logging.
     *
     * @return array<string, mixed>
     */
    private function checkpointAuditSnapshot(Checkpoint $checkpoint): array
    {
        return [
            'id' => $checkpoint->id,
            'event_id' => $checkpoint->event_id,
            'venue_id' => $checkpoint->venue_id,
            'name' => $checkpoint->name,
            'description' => $checkpoint->description,
        ];
    }
}
