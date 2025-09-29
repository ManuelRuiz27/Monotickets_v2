<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithTenants;
use App\Http\Requests\HostessAssignment\HostessAssignmentIndexRequest;
use App\Http\Requests\HostessAssignment\HostessAssignmentStoreRequest;
use App\Http\Requests\HostessAssignment\HostessAssignmentUpdateRequest;
use App\Models\Checkpoint;
use App\Models\Event;
use App\Models\HostessAssignment;
use App\Models\Role;
use App\Models\User;
use App\Models\Venue;
use App\Support\ApiResponse;
use App\Support\Formatters\HostessAssignmentFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Manage hostess assignments within a tenant scope.
 */
class HostessAssignmentController extends Controller
{
    use InteractsWithTenants;

    /**
     * Display hostess assignments for the current tenant context.
     */
    public function index(HostessAssignmentIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', HostessAssignment::class);

        /** @var User $authUser */
        $authUser = $request->user();
        $authUser->loadMissing('roles');

        $filters = $request->validated();
        $tenantId = $this->resolveTenantForAction($request, $authUser, $filters['tenant_id'] ?? null);

        if ($tenantId === null) {
            $this->throwValidationException([
                'tenant_id' => ['Unable to determine tenant context for assignment listing.'],
            ]);
        }

        $query = HostessAssignment::query()
            ->with(['hostess', 'event', 'venue', 'checkpoint'])
            ->forTenant($tenantId)
            ->orderBy('starts_at');

        if (isset($filters['event_id'])) {
            $query->where('event_id', $filters['event_id']);
        }

        if (isset($filters['hostess_user_id'])) {
            $query->where('hostess_user_id', $filters['hostess_user_id']);
        }

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $assignments = $query->get();

        return response()->json([
            'data' => $assignments
                ->map(fn (HostessAssignment $assignment): array => HostessAssignmentFormatter::format($assignment))
                ->values()
                ->all(),
        ]);
    }

    /**
     * Store a new hostess assignment for the tenant.
     */
    public function store(HostessAssignmentStoreRequest $request): JsonResponse
    {
        $this->authorize('create', HostessAssignment::class);

        /** @var User $authUser */
        $authUser = $request->user();
        $authUser->loadMissing('roles');

        $payload = $request->validated();
        $tenantId = $this->resolveTenantForAction($request, $authUser, $payload['tenant_id'] ?? null);

        if ($tenantId === null) {
            $this->throwValidationException([
                'tenant_id' => ['Tenant context is required to create assignments.'],
            ]);
        }

        [$event, $venue, $checkpoint] = $this->resolveAssignmentScope(
            $payload['event_id'],
            $payload['venue_id'] ?? null,
            $payload['checkpoint_id'] ?? null,
            $tenantId
        );

        $hostess = $this->resolveHostess($payload['hostess_user_id'], $tenantId);
        $startsAt = Carbon::parse($payload['starts_at']);
        $endsAt = isset($payload['ends_at']) ? Carbon::parse($payload['ends_at']) : null;
        $this->assertValidWindow($startsAt, $endsAt);

        $assignment = HostessAssignment::create([
            'tenant_id' => $tenantId,
            'hostess_user_id' => $hostess->id,
            'event_id' => $event->id,
            'venue_id' => $venue?->id,
            'checkpoint_id' => $checkpoint?->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
        ]);

        $assignment->load(['hostess', 'event', 'venue', 'checkpoint']);

        return response()->json([
            'data' => HostessAssignmentFormatter::format($assignment),
        ], 201);
    }

    /**
     * Display the specified hostess assignment.
     */
    public function show(Request $request, string $assignment_id): JsonResponse
    {
        $assignmentId = $assignment_id;
        $assignment = $this->findAssignment($assignmentId);

        if ($assignment === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $this->authorize('view', $assignment);

        $assignment->loadMissing(['hostess', 'event', 'venue', 'checkpoint']);

        return response()->json([
            'data' => HostessAssignmentFormatter::format($assignment),
        ]);
    }

    /**
     * Update the specified hostess assignment.
     */
    public function update(HostessAssignmentUpdateRequest $request, string $assignment_id): JsonResponse
    {
        $assignmentId = $assignment_id;
        $assignment = $this->findAssignment($assignmentId);

        if ($assignment === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $this->authorize('update', $assignment);

        /** @var User $authUser */
        $authUser = $request->user();
        $authUser->loadMissing('roles');

        $payload = $request->validated();
        $tenantId = (string) $assignment->tenant_id;

        $hostessId = array_key_exists('hostess_user_id', $payload)
            ? (string) $payload['hostess_user_id']
            : (string) $assignment->hostess_user_id;

        $eventId = array_key_exists('event_id', $payload)
            ? (string) $payload['event_id']
            : (string) $assignment->event_id;

        $venueId = array_key_exists('venue_id', $payload)
            ? $payload['venue_id']
            : ($assignment->venue_id !== null ? (string) $assignment->venue_id : null);

        $checkpointId = array_key_exists('checkpoint_id', $payload)
            ? $payload['checkpoint_id']
            : ($assignment->checkpoint_id !== null ? (string) $assignment->checkpoint_id : null);

        [$event, $venue, $checkpoint] = $this->resolveAssignmentScope($eventId, $venueId, $checkpointId, $tenantId);
        $hostess = $this->resolveHostess($hostessId, $tenantId);

        $startsAt = array_key_exists('starts_at', $payload)
            ? Carbon::parse($payload['starts_at'])
            : $assignment->starts_at;

        $endsAt = array_key_exists('ends_at', $payload)
            ? ($payload['ends_at'] !== null ? Carbon::parse($payload['ends_at']) : null)
            : $assignment->ends_at;

        if ($startsAt === null) {
            $this->throwValidationException([
                'starts_at' => ['The assignment start time is required.'],
            ]);
        }

        $this->assertValidWindow($startsAt, $endsAt);

        if (array_key_exists('is_active', $payload)) {
            $assignment->is_active = (bool) $payload['is_active'];
        }

        $assignment->hostess_user_id = $hostess->id;
        $assignment->event_id = $event->id;
        $assignment->venue_id = $venue?->id;
        $assignment->checkpoint_id = $checkpoint?->id;
        $assignment->starts_at = $startsAt;
        $assignment->ends_at = $endsAt;

        if ($assignment->isDirty()) {
            $assignment->save();
        }

        $assignment->load(['hostess', 'event', 'venue', 'checkpoint']);

        return response()->json([
            'data' => HostessAssignmentFormatter::format($assignment),
        ]);
    }

    /**
     * Remove the specified hostess assignment.
     */
    public function destroy(Request $request, string $assignment_id): JsonResponse
    {
        $assignmentId = $assignment_id;
        $assignment = $this->findAssignment($assignmentId);

        if ($assignment === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $this->authorize('delete', $assignment);

        $assignment->delete();

        return response()->json(null, 204);
    }

    /**
     * Resolve tenant context based on payload override when available.
     */
    private function resolveTenantForAction(Request $request, User $authUser, ?string $tenantOverride): ?string
    {
        if ($tenantOverride !== null) {
            if (! $this->isSuperAdmin($authUser)) {
                $this->throwValidationException([
                    'tenant_id' => ['Only super administrators may select a tenant explicitly.'],
                ]);
            }

            return $tenantOverride;
        }

        return $this->resolveTenantContext($request, $authUser);
    }

    /**
     * Retrieve an assignment by identifier.
     */
    private function findAssignment(string $assignmentId): ?HostessAssignment
    {
        return HostessAssignment::query()->find($assignmentId);
    }

    /**
     * Resolve and validate assignment scope components.
     *
     * @return array{0: Event, 1: ?Venue, 2: ?Checkpoint}
     */
    private function resolveAssignmentScope(string $eventId, ?string $venueId, ?string $checkpointId, string $tenantId): array
    {
        $event = Event::query()->where('tenant_id', $tenantId)->find($eventId);

        if ($event === null) {
            $this->throwValidationException([
                'event_id' => ['The selected event does not belong to the tenant.'],
            ]);
        }

        $venue = null;
        if ($venueId !== null) {
            $venue = Venue::query()->where('event_id', $event->id)->find($venueId);

            if ($venue === null) {
                $this->throwValidationException([
                    'venue_id' => ['The selected venue must belong to the event.'],
                ]);
            }
        }

        $checkpoint = null;
        if ($checkpointId !== null) {
            $checkpoint = Checkpoint::query()
                ->with('venue')
                ->where('event_id', $event->id)
                ->find($checkpointId);

            if ($checkpoint === null) {
                $this->throwValidationException([
                    'checkpoint_id' => ['The selected checkpoint must belong to the event.'],
                ]);
            }

            if ($venue !== null && (string) $checkpoint->venue_id !== (string) $venue->id) {
                $this->throwValidationException([
                    'checkpoint_id' => ['The checkpoint must belong to the selected venue.'],
                ]);
            }

            if ($venue === null) {
                $venue = $checkpoint->venue;
            }
        }

        return [$event, $venue, $checkpoint];
    }

    /**
     * Ensure the hostess belongs to the tenant and has the hostess role.
     */
    private function resolveHostess(string $hostessId, string $tenantId): User
    {
        $hostess = User::query()->with('roles')->find($hostessId);

        if ($hostess === null) {
            $this->throwValidationException([
                'hostess_user_id' => ['The selected hostess could not be found.'],
            ]);
        }

        $hasHostessRole = $hostess->roles->contains(
            fn (Role $role): bool => $role->code === 'hostess'
                && (string) ($role->pivot->tenant_id ?? $hostess->tenant_id) === (string) $tenantId
        );

        if (! $hasHostessRole) {
            $this->throwValidationException([
                'hostess_user_id' => ['The selected user must have the hostess role within the tenant.'],
            ]);
        }

        return $hostess;
    }

    /**
     * Validate that the assignment window is chronologically valid.
     */
    private function assertValidWindow(Carbon $startsAt, ?Carbon $endsAt): void
    {
        if ($endsAt !== null && $endsAt->lessThanOrEqualTo($startsAt)) {
            $this->throwValidationException([
                'ends_at' => ['The end time must be after the start time.'],
            ]);
        }
    }
}
