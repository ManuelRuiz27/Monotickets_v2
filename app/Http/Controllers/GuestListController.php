<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithTenants;
use App\Http\Requests\GuestList\GuestListIndexRequest;
use App\Http\Requests\GuestList\GuestListStoreRequest;
use App\Http\Requests\GuestList\GuestListUpdateRequest;
use App\Models\Event;
use App\Models\GuestList;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Audit\RecordsAuditLogs;
use App\Support\Logging\StructuredLogging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Manage guest lists linked to events.
 */
class GuestListController extends Controller
{
    use InteractsWithTenants;
    use RecordsAuditLogs;
    use StructuredLogging;

    /**
     * Display a paginated listing of guest lists for an event.
     */
    public function index(GuestListIndexRequest $request, string $event_id): JsonResponse
    {
        $eventId = $event_id;
        /** @var User $authUser */
        $authUser = $request->user();
        $event = $this->locateEvent($request, $authUser, $eventId);

        if ($event === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 15);

        /** @var LengthAwarePaginator $paginator */
        $paginator = GuestList::query()
            ->where('event_id', $event->id)
            ->orderBy('name')
            ->paginate($perPage);

        $paginator->getCollection()->transform(fn (GuestList $guestList): array => $this->formatGuestList($guestList));

        return ApiResponse::paginate($paginator->items(), [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'total_pages' => $paginator->lastPage(),
        ]);
    }

    /**
     * Store a newly created guest list for the event.
     */
    public function store(GuestListStoreRequest $request, string $event_id): JsonResponse
    {
        $eventId = $event_id;
        /** @var User $authUser */
        $authUser = $request->user();
        $event = $this->locateEvent($request, $authUser, $eventId);

        if ($event === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $validated = $request->validated();

        $guestList = new GuestList();
        $guestList->fill($validated);
        $guestList->event_id = $event->id;
        $guestList->save();
        $guestList->refresh();

        $snapshot = $this->guestListAuditSnapshot($guestList);

        $this->recordAuditLog($authUser, $request, 'guest_list', $guestList->id, 'created', [
            'after' => $snapshot,
        ], $event->tenant_id);

        $this->logEntityLifecycle(
            $request,
            $authUser,
            'guest_list',
            (string) $guestList->id,
            'created',
            (string) $event->tenant_id,
            [
                'event_id' => $event->id,
                'after' => $snapshot,
            ]
        );

        $this->logLifecycleMetric(
            $request,
            $authUser,
            'guest_lists_created',
            'guest_list',
            (string) $guestList->id,
            (string) $event->tenant_id,
            [
                'event_id' => $event->id,
            ]
        );

        return response()->json([
            'data' => $this->formatGuestList($guestList),
        ], 201);
    }

    /**
     * Display the specified guest list.
     */
    public function show(Request $request, string $guest_list_id): JsonResponse
    {
        $guestListId = $guest_list_id;
        /** @var User $authUser */
        $authUser = $request->user();
        $guestList = $this->locateGuestList($request, $authUser, $guestListId);

        if ($guestList === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        return response()->json([
            'data' => $this->formatGuestList($guestList),
        ]);
    }

    /**
     * Update the specified guest list.
     */
    public function update(GuestListUpdateRequest $request, string $guest_list_id): JsonResponse
    {
        $guestListId = $guest_list_id;
        /** @var User $authUser */
        $authUser = $request->user();
        $guestList = $this->locateGuestList($request, $authUser, $guestListId);

        if ($guestList === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $validated = $request->validated();

        if ($validated !== []) {
            $original = $this->guestListAuditSnapshot($guestList);

            $guestList->fill($validated);
            $guestList->save();
            $guestList->refresh();

            $updated = $this->guestListAuditSnapshot($guestList);
            $changes = $this->calculateDifferences($original, $updated);

            if ($changes !== []) {
                $this->recordAuditLog($authUser, $request, 'guest_list', $guestList->id, 'updated', [
                    'changes' => $changes,
                ], $guestList->event->tenant_id);

                $this->logEntityLifecycle(
                    $request,
                    $authUser,
                    'guest_list',
                    (string) $guestList->id,
                    'updated',
                    (string) $guestList->event->tenant_id,
                    [
                        'event_id' => $guestList->event_id,
                        'changes' => $changes,
                    ]
                );
            }
        }

        return response()->json([
            'data' => $this->formatGuestList($guestList),
        ]);
    }

    /**
     * Soft delete the specified guest list.
     */
    public function destroy(Request $request, string $guest_list_id): JsonResponse
    {
        $guestListId = $guest_list_id;
        /** @var User $authUser */
        $authUser = $request->user();
        $guestList = $this->locateGuestList($request, $authUser, $guestListId);

        if ($guestList === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $snapshot = $this->guestListAuditSnapshot($guestList);
        $tenantId = (string) $guestList->event->tenant_id;

        $guestList->delete();

        $this->recordAuditLog($authUser, $request, 'guest_list', $guestList->id, 'deleted', [
            'before' => $snapshot,
        ], $tenantId);

        $this->logEntityLifecycle(
            $request,
            $authUser,
            'guest_list',
            (string) $guestList->id,
            'deleted',
            $tenantId,
            [
                'event_id' => $guestList->event_id,
                'before' => $snapshot,
            ]
        );

        return response()->json(null, 204);
    }

    /**
     * Locate an event ensuring tenant constraints.
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
     * Locate a guest list ensuring tenant constraints.
     */
    private function locateGuestList(Request $request, User $authUser, string $guestListId): ?GuestList
    {
        $query = GuestList::query()->with('event')->whereKey($guestListId);
        $tenantId = $this->resolveTenantContext($request, $authUser);

        if ($this->isSuperAdmin($authUser)) {
            if ($tenantId !== null) {
                $query->whereHas('event', function (Builder $builder) use ($tenantId): void {
                    $builder->where('tenant_id', $tenantId);
                });
            }
        } else {
            if ($tenantId === null) {
                $this->throwValidationException([
                    'tenant_id' => ['Unable to determine tenant context.'],
                ]);
            }

            $query->whereHas('event', function (Builder $builder) use ($tenantId): void {
                $builder->where('tenant_id', $tenantId);
            });
        }

        return $query->first();
    }

    /**
     * Format the guest list resource for API responses.
     *
     * @return array<string, mixed>
     */
    private function formatGuestList(GuestList $guestList): array
    {
        return [
            'id' => $guestList->id,
            'event_id' => $guestList->event_id,
            'name' => $guestList->name,
            'description' => $guestList->description,
            'criteria_json' => $guestList->criteria_json,
            'created_at' => optional($guestList->created_at)->toISOString(),
            'updated_at' => optional($guestList->updated_at)->toISOString(),
        ];
    }

    /**
     * Build an audit snapshot for the guest list.
     *
     * @return array<string, mixed>
     */
    private function guestListAuditSnapshot(GuestList $guestList): array
    {
        return [
            'id' => $guestList->id,
            'event_id' => $guestList->event_id,
            'name' => $guestList->name,
            'description' => $guestList->description,
            'criteria_json' => $guestList->criteria_json,
        ];
    }
}
