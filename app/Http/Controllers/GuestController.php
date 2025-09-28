<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithTenants;
use App\Http\Params\SearchParam;
use App\Http\Requests\Guest\GuestIndexRequest;
use App\Http\Requests\Guest\GuestStoreRequest;
use App\Http\Requests\Guest\GuestUpdateRequest;
use App\Models\Event;
use App\Models\Guest;
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
 * Manage guests associated with events.
 */
class GuestController extends Controller
{
    use InteractsWithTenants;
    use RecordsAuditLogs;
    use StructuredLogging;

    /**
     * Display a paginated listing of guests for an event.
     */
    public function index(GuestIndexRequest $request, string $eventId): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $event = $this->locateEvent($request, $authUser, $eventId);

        if ($event === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $filters = $request->validated();
        $query = Guest::query()->where('event_id', $event->id);

        if (! empty($filters['rsvp_status'])) {
            $query->whereIn('rsvp_status', $filters['rsvp_status']);
        }

        if (array_key_exists('list', $filters)) {
            $guestListId = $filters['list'];

            if ($guestListId === null) {
                $query->whereNull('guest_list_id');
            } else {
                $this->assertGuestListBelongsToEvent($event, $guestListId);
                $query->where('guest_list_id', $guestListId);
            }
        }

        $search = SearchParam::fromString($filters['search'] ?? null);

        if ($search !== null) {
            $search->apply($query, ['full_name', 'email', 'phone', 'organization']);
        }

        $perPage = (int) ($filters['per_page'] ?? 15);

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query
            ->orderBy('full_name')
            ->paginate($perPage);

        $paginator->getCollection()->transform(fn (Guest $guest): array => $this->formatGuest($guest));

        return ApiResponse::paginate($paginator->items(), [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'total_pages' => $paginator->lastPage(),
        ]);
    }

    /**
     * Store a newly created guest for the event.
     */
    public function store(GuestStoreRequest $request, string $eventId): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $event = $this->locateEvent($request, $authUser, $eventId);

        if ($event === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $validated = $request->validated();
        $guestListId = $validated['guest_list_id'] ?? null;

        if ($guestListId !== null) {
            $this->assertGuestListBelongsToEvent($event, $guestListId);
        }

        $guest = new Guest();
        $guest->fill($validated);
        $guest->event_id = $event->id;
        $guest->save();
        $guest->refresh();

        $snapshot = $this->guestAuditSnapshot($guest);

        $this->recordAuditLog($authUser, $request, 'guest', $guest->id, 'created', [
            'after' => $snapshot,
        ], $event->tenant_id);

        $this->logEntityLifecycle(
            $request,
            $authUser,
            'guest',
            (string) $guest->id,
            'created',
            (string) $event->tenant_id,
            [
                'event_id' => $event->id,
                'guest_list_id' => $guest->guest_list_id,
                'after' => $snapshot,
            ]
        );

        $this->logLifecycleMetric(
            $request,
            $authUser,
            'guests_created',
            'guest',
            (string) $guest->id,
            (string) $event->tenant_id,
            [
                'event_id' => $event->id,
            ]
        );

        return response()->json([
            'data' => $this->formatGuest($guest),
        ], 201);
    }

    /**
     * Display the specified guest.
     */
    public function show(Request $request, string $guestId): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $guest = $this->locateGuest($request, $authUser, $guestId);

        if ($guest === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        return response()->json([
            'data' => $this->formatGuest($guest),
        ]);
    }

    /**
     * Update the specified guest.
     */
    public function update(GuestUpdateRequest $request, string $guestId): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $guest = $this->locateGuest($request, $authUser, $guestId);

        if ($guest === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $validated = $request->validated();

        if (array_key_exists('guest_list_id', $validated)) {
            $this->assertGuestListBelongsToEvent($guest->event, $validated['guest_list_id']);
        }

        if ($validated !== []) {
            $original = $this->guestAuditSnapshot($guest);

            $guest->fill($validated);
            $guest->save();
            $guest->refresh();

            $updated = $this->guestAuditSnapshot($guest);
            $changes = $this->calculateDifferences($original, $updated);

            if ($changes !== []) {
                $this->recordAuditLog($authUser, $request, 'guest', $guest->id, 'updated', [
                    'changes' => $changes,
                ], $guest->event->tenant_id);

                $this->logEntityLifecycle(
                    $request,
                    $authUser,
                    'guest',
                    (string) $guest->id,
                    'updated',
                    (string) $guest->event->tenant_id,
                    [
                        'event_id' => $guest->event_id,
                        'guest_list_id' => $guest->guest_list_id,
                        'changes' => $changes,
                    ]
                );
            }
        }

        return response()->json([
            'data' => $this->formatGuest($guest),
        ]);
    }

    /**
     * Soft delete the specified guest.
     */
    public function destroy(Request $request, string $guestId): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $guest = $this->locateGuest($request, $authUser, $guestId);

        if ($guest === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $snapshot = $this->guestAuditSnapshot($guest);
        $tenantId = (string) $guest->event->tenant_id;

        $guest->delete();

        $this->recordAuditLog($authUser, $request, 'guest', $guest->id, 'deleted', [
            'before' => $snapshot,
        ], $tenantId);

        $this->logEntityLifecycle(
            $request,
            $authUser,
            'guest',
            (string) $guest->id,
            'deleted',
            $tenantId,
            [
                'event_id' => $guest->event_id,
                'guest_list_id' => $guest->guest_list_id,
                'before' => $snapshot,
            ]
        );

        return response()->json(null, 204);
    }

    /**
     * Locate an event ensuring tenant constraints.
     */
    private function locateEvent(Request $request, User $authUser, string $eventId): ?Event
    {
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
     * Locate a guest ensuring tenant constraints.
     */
    private function locateGuest(Request $request, User $authUser, string $guestId): ?Guest
    {
        $query = Guest::query()->with('event')->whereKey($guestId);
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
     * Ensure the provided guest list belongs to the event.
     */
    private function assertGuestListBelongsToEvent(Event $event, ?string $guestListId): void
    {
        if ($guestListId === null) {
            return;
        }

        $exists = GuestList::query()
            ->where('event_id', $event->id)
            ->whereKey($guestListId)
            ->exists();

        if (! $exists) {
            $this->throwValidationException([
                'guest_list_id' => ['The selected guest list does not belong to the event.'],
            ]);
        }
    }

    /**
     * Format the guest resource for API responses.
     *
     * @return array<string, mixed>
     */
    private function formatGuest(Guest $guest): array
    {
        return [
            'id' => $guest->id,
            'event_id' => $guest->event_id,
            'guest_list_id' => $guest->guest_list_id,
            'full_name' => $guest->full_name,
            'email' => $guest->email,
            'phone' => $guest->phone,
            'organization' => $guest->organization,
            'rsvp_status' => $guest->rsvp_status,
            'rsvp_at' => optional($guest->rsvp_at)->toISOString(),
            'allow_plus_ones' => $guest->allow_plus_ones,
            'plus_ones_limit' => $guest->plus_ones_limit,
            'custom_fields_json' => $guest->custom_fields_json,
            'created_at' => optional($guest->created_at)->toISOString(),
            'updated_at' => optional($guest->updated_at)->toISOString(),
        ];
    }

    /**
     * Build an audit snapshot for the guest.
     *
     * @return array<string, mixed>
     */
    private function guestAuditSnapshot(Guest $guest): array
    {
        return [
            'id' => $guest->id,
            'event_id' => $guest->event_id,
            'guest_list_id' => $guest->guest_list_id,
            'full_name' => $guest->full_name,
            'email' => $guest->email,
            'phone' => $guest->phone,
            'organization' => $guest->organization,
            'rsvp_status' => $guest->rsvp_status,
            'rsvp_at' => optional($guest->rsvp_at)->toISOString(),
            'allow_plus_ones' => $guest->allow_plus_ones,
            'plus_ones_limit' => $guest->plus_ones_limit,
            'custom_fields_json' => $guest->custom_fields_json,
        ];
    }
}
