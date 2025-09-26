<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithTenants;
use App\Http\Params\SearchParam;
use App\Http\Requests\Event\EventIndexRequest;
use App\Http\Requests\Event\EventStoreRequest;
use App\Http\Requests\Event\EventUpdateRequest;
use App\Models\Event;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Audit\RecordsAuditLogs;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Manage lifecycle operations for events.
 */
class EventController extends Controller
{
    use InteractsWithTenants;
    use RecordsAuditLogs;

    /**
     * Display a paginated list of events with filtering capabilities.
     */
    public function index(EventIndexRequest $request): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $filters = $request->validated();

        $query = Event::query();

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

        if (! empty($filters['status'])) {
            $query->whereIn('status', $filters['status']);
        }

        if (isset($filters['from'])) {
            $query->where('start_at', '>=', CarbonImmutable::parse($filters['from']));
        }

        if (isset($filters['to'])) {
            $query->where('start_at', '<=', CarbonImmutable::parse($filters['to']));
        }

        $search = SearchParam::fromString($filters['search'] ?? null);

        if ($search !== null) {
            $search->apply($query, ['code', 'name', 'description']);
        }

        $perPage = (int) ($filters['per_page'] ?? 15);

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query
            ->orderBy('start_at')
            ->paginate($perPage);

        $paginator->getCollection()->transform(fn (Event $event): array => $this->formatEvent($event));

        return ApiResponse::paginate($paginator->items(), [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'total_pages' => $paginator->lastPage(),
        ]);
    }

    /**
     * Store a newly created event in storage.
     */
    public function store(EventStoreRequest $request): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $validated = $request->validated();

        $tenantId = $this->resolveTenantContext($request, $authUser);

        if ($this->isSuperAdmin($authUser) && array_key_exists('tenant_id', $validated) && $validated['tenant_id'] !== null) {
            $tenantId = $validated['tenant_id'];
        }

        if ($tenantId === null) {
            $this->throwValidationException([
                'tenant_id' => ['Tenant context is required to create events.'],
            ]);
        }

        if (! $this->organizerBelongsToTenant($validated['organizer_user_id'], $tenantId)) {
            $this->throwValidationException([
                'organizer_user_id' => ['The organizer must belong to the selected tenant.'],
            ]);
        }

        $this->assertUniqueEventCode($tenantId, $validated['code']);

        /** @var Event $event */
        $event = DB::transaction(function () use ($validated, $tenantId) {
            $event = new Event();
            $event->fill(Arr::except($validated, ['tenant_id']));
            $event->tenant_id = $tenantId;
            $event->start_at = CarbonImmutable::parse($validated['start_at']);
            $event->end_at = CarbonImmutable::parse($validated['end_at']);
            $event->settings_json = $validated['settings_json'] ?? null;
            $event->save();

            return $event->refresh();
        });

        $this->recordAuditLog($authUser, $request, 'event', $event->id, 'created', [
            'after' => $this->eventAuditSnapshot($event),
        ], $event->tenant_id);

        return response()->json([
            'data' => $this->formatEvent($event),
        ], 201);
    }

    /**
     * Display the specified event.
     */
    public function show(Request $request, string $eventId): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $event = $this->locateEvent($request, $authUser, $eventId);

        if ($event === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        return response()->json([
            'data' => $this->formatEvent($event),
        ]);
    }

    /**
     * Update the specified event in storage.
     */
    public function update(EventUpdateRequest $request, string $eventId): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $event = $this->locateEvent($request, $authUser, $eventId);

        if ($event === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $validated = $request->validated();

        if (isset($validated['code'])) {
            $this->assertUniqueEventCode($event->tenant_id, $validated['code'], $event->id);
        }

        if (isset($validated['organizer_user_id'])
            && ! $this->organizerBelongsToTenant($validated['organizer_user_id'], (string) $event->tenant_id)
        ) {
            $this->throwValidationException([
                'organizer_user_id' => ['The organizer must belong to the selected tenant.'],
            ]);
        }

        $startAt = array_key_exists('start_at', $validated)
            ? CarbonImmutable::parse($validated['start_at'])
            : ($event->start_at?->toImmutable());

        $endAt = array_key_exists('end_at', $validated)
            ? CarbonImmutable::parse($validated['end_at'])
            : ($event->end_at?->toImmutable());

        if ($startAt !== null && $endAt !== null && $startAt->gte($endAt)) {
            $this->throwValidationException([
                'end_at' => ['The end date must be after the start date.'],
            ]);
        }

        $payload = Arr::except($validated, ['start_at', 'end_at']);

        $originalSnapshot = $this->eventAuditSnapshot($event);

        if ($payload !== []) {
            $event->fill($payload);
        }

        if (array_key_exists('settings_json', $validated)) {
            $event->settings_json = $validated['settings_json'];
        }

        if (array_key_exists('start_at', $validated)) {
            $event->start_at = $startAt;
        }

        if (array_key_exists('end_at', $validated)) {
            $event->end_at = $endAt;
        }

        $event->save();
        $event->refresh();

        $updatedSnapshot = $this->eventAuditSnapshot($event);
        $changes = $this->calculateDifferences($originalSnapshot, $updatedSnapshot);

        if ($changes !== []) {
            $this->recordAuditLog($authUser, $request, 'event', $event->id, 'updated', [
                'changes' => $changes,
            ], $event->tenant_id);
        }

        return response()->json([
            'data' => $this->formatEvent($event),
        ]);
    }

    /**
     * Soft delete the specified event from storage.
     */
    public function destroy(Request $request, string $eventId): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $event = $this->locateEvent($request, $authUser, $eventId);

        if ($event === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $snapshot = $this->eventAuditSnapshot($event);

        $event->delete();

        $this->recordAuditLog($authUser, $request, 'event', $event->id, 'deleted', [
            'before' => $snapshot,
        ], $event->tenant_id);

        return response()->json(null, 204);
    }

    /**
     * Check the uniqueness of the event code within the tenant scope.
     */
    private function assertUniqueEventCode(string $tenantId, string $code, ?string $ignoreId = null): void
    {
        $query = Event::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $code);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        if ($query->exists()) {
            $this->throwValidationException([
                'code' => ['The code has already been taken for this tenant.'],
            ]);
        }
    }

    /**
     * Determine if the organiser belongs to the given tenant.
     */
    private function organizerBelongsToTenant(string $organizerId, string $tenantId): bool
    {
        return User::query()
            ->whereKey($organizerId)
            ->where('tenant_id', $tenantId)
            ->exists();
    }

    /**
     * Locate an event for the current tenant context.
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
     * Format an event model for API responses.
     *
     * @return array<string, mixed>
     */
    private function formatEvent(Event $event): array
    {
        return [
            'id' => $event->id,
            'tenant_id' => $event->tenant_id,
            'organizer_user_id' => $event->organizer_user_id,
            'code' => $event->code,
            'name' => $event->name,
            'description' => $event->description,
            'start_at' => optional($event->start_at)->toISOString(),
            'end_at' => optional($event->end_at)->toISOString(),
            'timezone' => $event->timezone,
            'status' => $event->status,
            'capacity' => $event->capacity,
            'checkin_policy' => $event->checkin_policy,
            'settings_json' => $event->settings_json,
            'created_at' => optional($event->created_at)->toISOString(),
            'updated_at' => optional($event->updated_at)->toISOString(),
        ];
    }

    /**
     * Build a normalized snapshot of the event for audit logging.
     *
     * @return array<string, mixed>
     */
    private function eventAuditSnapshot(Event $event): array
    {
        return [
            'id' => $event->id,
            'tenant_id' => $event->tenant_id,
            'organizer_user_id' => $event->organizer_user_id,
            'code' => $event->code,
            'name' => $event->name,
            'description' => $event->description,
            'start_at' => optional($event->start_at)->toISOString(),
            'end_at' => optional($event->end_at)->toISOString(),
            'timezone' => $event->timezone,
            'status' => $event->status,
            'capacity' => $event->capacity,
            'checkin_policy' => $event->checkin_policy,
            'settings_json' => $event->settings_json,
        ];
    }
}
