<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithTenants;
use App\Http\Requests\Venue\VenueIndexRequest;
use App\Http\Requests\Venue\VenueStoreRequest;
use App\Http\Requests\Venue\VenueUpdateRequest;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Handle CRUD operations for event venues.
 */
class VenueController extends Controller
{
    use InteractsWithTenants;

    /**
     * Display a paginated listing of venues for the given event.
     */
    public function index(VenueIndexRequest $request, string $eventId): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $event = $this->locateEvent($request, $authUser, $eventId);

        if ($event === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 15);

        /** @var LengthAwarePaginator $paginator */
        $paginator = Venue::query()
            ->where('event_id', $event->id)
            ->orderBy('name')
            ->paginate($perPage);

        $paginator->getCollection()->transform(fn (Venue $venue): array => $this->formatVenue($venue));

        return ApiResponse::paginate($paginator->items(), [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'total_pages' => $paginator->lastPage(),
        ]);
    }

    /**
     * Store a newly created venue for the event.
     */
    public function store(VenueStoreRequest $request, string $eventId): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $event = $this->locateEvent($request, $authUser, $eventId);

        if ($event === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $validated = $request->validated();

        $venue = new Venue();
        $venue->fill($validated);
        $venue->event_id = $event->id;
        $venue->save();

        return response()->json([
            'data' => $this->formatVenue($venue->refresh()),
        ], 201);
    }

    /**
     * Display the specified venue.
     */
    public function show(Request $request, string $eventId, string $venueId): JsonResponse
    {
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

        return response()->json([
            'data' => $this->formatVenue($venue),
        ]);
    }

    /**
     * Update the specified venue for the event.
     */
    public function update(VenueUpdateRequest $request, string $eventId, string $venueId): JsonResponse
    {
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

        if ($validated !== []) {
            $venue->fill($validated);
            $venue->save();
        }

        return response()->json([
            'data' => $this->formatVenue($venue->refresh()),
        ]);
    }

    /**
     * Remove the specified venue from storage.
     */
    public function destroy(Request $request, string $eventId, string $venueId): JsonResponse
    {
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

        $venue->delete();

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
     * Locate a venue ensuring it belongs to the provided event.
     */
    private function locateVenue(Event $event, string $venueId): ?Venue
    {
        return Venue::query()
            ->where('event_id', $event->id)
            ->whereKey($venueId)
            ->first();
    }

    /**
     * Format the venue resource for API responses.
     *
     * @return array<string, mixed>
     */
    private function formatVenue(Venue $venue): array
    {
        return [
            'id' => $venue->id,
            'event_id' => $venue->event_id,
            'name' => $venue->name,
            'address' => $venue->address,
            'lat' => $venue->lat,
            'lng' => $venue->lng,
            'notes' => $venue->notes,
            'created_at' => optional($venue->created_at)->toISOString(),
            'updated_at' => optional($venue->updated_at)->toISOString(),
        ];
    }
}
