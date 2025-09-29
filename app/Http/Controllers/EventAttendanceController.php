<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithTenants;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\HostessAssignment;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Provide reconciliation endpoints for ticket attendances and state.
 */
class EventAttendanceController extends Controller
{
    use InteractsWithTenants;

    /**
     * Return attendances captured for an event after the provided cursor.
     */
    public function attendancesSince(Request $request, string $event_id): JsonResponse
    {
        $eventId = $event_id;
        /** @var User $authUser */
        $authUser = $request->user();
        $event = $this->locateEvent($request, $authUser, $eventId);

        if ($event === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $this->assertCanAccessEvent($authUser, $event);

        $validated = $this->validate($request, [
            'cursor' => ['nullable', 'date'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        $limit = (int) Arr::get($validated, 'limit', 200);
        $cursorTime = null;

        if (isset($validated['cursor'])) {
            $cursorTime = CarbonImmutable::parse((string) $validated['cursor']);
        }

        $query = Attendance::query()
            ->where('event_id', $event->id)
            ->orderBy('scanned_at')
            ->orderBy('id');

        if ($cursorTime !== null) {
            $query->where('scanned_at', '>', $cursorTime);
        }

        $attendances = $query->limit($limit + 1)->get();
        $hasMore = $attendances->count() > $limit;

        if ($hasMore) {
            $attendances = $attendances->take($limit);
        }

        $nextCursor = $hasMore && $attendances->isNotEmpty()
            ? optional($attendances->last()->scanned_at)->toISOString()
            : null;

        return response()->json([
            'data' => $attendances
                ->map(fn (Attendance $attendance): array => $this->formatAttendance($attendance))
                ->values(),
            'meta' => [
                'next_cursor' => $nextCursor,
            ],
        ]);
    }

    /**
     * Retrieve the current state of a ticket for the provided event.
     */
    public function ticketState(Request $request, string $event_id, string $ticket_id): JsonResponse
    {
        $eventId = $event_id;
        $ticketId = $ticket_id;
        /** @var User $authUser */
        $authUser = $request->user();
        $event = $this->locateEvent($request, $authUser, $eventId);

        if ($event === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $this->assertCanAccessEvent($authUser, $event);

        $ticket = Ticket::query()
            ->where('event_id', $event->id)
            ->whereKey($ticketId)
            ->first();

        if ($ticket === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $ticket->loadMissing(['guest', 'event']);

        $lastAttendance = $ticket->attendances()
            ->orderByDesc('scanned_at')
            ->orderByDesc('created_at')
            ->first();

        return response()->json([
            'data' => [
                'ticket' => $this->formatTicket($ticket),
                'last_attendance' => $lastAttendance !== null ? $this->formatAttendance($lastAttendance) : null,
            ],
        ]);
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
     * Ensure hostess users have an active assignment for the event.
     */
    private function assertCanAccessEvent(User $authUser, Event $event): void
    {
        if ($this->isSuperAdmin($authUser)) {
            return;
        }

        $authUser->loadMissing('roles');
        $isHostess = $authUser->roles->contains(fn ($role): bool => $role->code === 'hostess');

        if (! $isHostess) {
            // Organizers are allowed without additional checks.
            return;
        }

        $now = Carbon::now();

        $hasAssignment = HostessAssignment::query()
            ->forTenant((string) $event->tenant_id)
            ->where('hostess_user_id', $authUser->id)
            ->where('event_id', $event->id)
            ->currentlyActive($now)
            ->exists();

        if (! $hasAssignment) {
            abort(403, 'Hostess does not have an active assignment for this event.');
        }
    }

    /**
     * Format the attendance resource for API responses.
     *
     * @return array<string, mixed>
     */
    private function formatAttendance(Attendance $attendance): array
    {
        $attendance->loadMissing('checkpoint');

        return [
            'id' => $attendance->id,
            'event_id' => $attendance->event_id,
            'ticket_id' => $attendance->ticket_id,
            'guest_id' => $attendance->guest_id,
            'result' => $attendance->result,
            'checkpoint_id' => $attendance->checkpoint_id,
            'hostess_user_id' => $attendance->hostess_user_id,
            'scanned_at' => optional($attendance->scanned_at)->toISOString(),
            'device_id' => $attendance->device_id,
            'offline' => $attendance->offline,
            'metadata' => $attendance->metadata_json,
        ];
    }

    /**
     * Format the ticket resource for API responses.
     *
     * @return array<string, mixed>
     */
    private function formatTicket(Ticket $ticket): array
    {
        $ticket->loadMissing(['guest', 'event']);

        return [
            'id' => $ticket->id,
            'event_id' => $ticket->event?->id,
            'status' => $ticket->status,
            'type' => $ticket->type,
            'issued_at' => optional($ticket->issued_at)->toISOString(),
            'expires_at' => optional($ticket->expires_at)->toISOString(),
            'guest' => $ticket->guest !== null ? [
                'id' => $ticket->guest->id,
                'full_name' => $ticket->guest->full_name,
            ] : null,
            'event' => $ticket->event !== null ? [
                'id' => $ticket->event->id,
                'name' => $ticket->event->name,
                'checkin_policy' => $ticket->event->checkin_policy,
            ] : null,
        ];
    }
}

