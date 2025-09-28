<?php

namespace App\Http\Controllers;

use App\Events\TicketIssued;
use App\Events\TicketRevoked;
use App\Http\Controllers\Concerns\InteractsWithTenants;
use App\Http\Requests\Ticket\TicketStoreRequest;
use App\Http\Requests\Ticket\TicketUpdateRequest;
use App\Models\Guest;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Audit\RecordsAuditLogs;
use App\Support\Logging\StructuredLogging;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use function event;

/**
 * Manage ticket lifecycle for guests.
 */
class TicketController extends Controller
{
    use InteractsWithTenants;
    use RecordsAuditLogs;
    use StructuredLogging;

    /**
     * List the tickets issued for a guest.
     */
    public function index(Request $request, string $guestId): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $guest = $this->locateGuest($request, $authUser, $guestId);

        if ($guest === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $tickets = Ticket::query()
            ->where('guest_id', $guest->id)
            ->orderByDesc('issued_at')
            ->get()
            ->map(fn (Ticket $ticket): array => $this->formatTicket($ticket));

        return response()->json([
            'data' => $tickets,
        ]);
    }

    /**
     * Issue a new ticket for the guest.
     */
    public function store(TicketStoreRequest $request, string $guestId): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $guest = $this->locateGuest($request, $authUser, $guestId);

        if ($guest === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $activeTickets = Ticket::query()
            ->where('guest_id', $guest->id)
            ->count();

        $limit = 1 + ($guest->allow_plus_ones ? (int) ($guest->plus_ones_limit ?? 0) : 0);

        if ($activeTickets >= $limit) {
            $this->throwValidationException([
                'guest_id' => ['The guest has reached the ticket issuing limit.'],
            ]);
        }

        $validated = $request->validated();

        $seatData = [
            'seat_section' => $validated['seat_section'] ?? null,
            'seat_row' => $validated['seat_row'] ?? null,
            'seat_code' => $validated['seat_code'] ?? null,
        ];

        $this->assertSeatAvailability((string) $guest->event_id, $seatData, null);

        $ticket = new Ticket();
        $ticket->event_id = $guest->event_id;
        $ticket->guest_id = $guest->id;
        $ticket->type = $validated['type'] ?? 'general';
        $ticket->price_cents = $validated['price_cents'] ?? 0;
        $ticket->status = 'issued';
        $ticket->seat_section = $seatData['seat_section'];
        $ticket->seat_row = $seatData['seat_row'];
        $ticket->seat_code = $seatData['seat_code'];
        $ticket->issued_at = now();
        $ticket->expires_at = $validated['expires_at'] ?? null;
        $ticket->save();
        $ticket->refresh();

        $snapshot = $this->ticketAuditSnapshot($ticket);
        $tenantId = (string) $guest->event->tenant_id;

        event(new TicketIssued($ticket, $authUser, $request, $tenantId, $snapshot));

        $this->logEntityLifecycle(
            $request,
            $authUser,
            'ticket',
            (string) $ticket->id,
            'issued',
            $tenantId,
            [
                'event_id' => $ticket->event_id,
                'guest_id' => $ticket->guest_id,
            ]
        );

        $this->logLifecycleMetric(
            $request,
            $authUser,
            'tickets_issued',
            'ticket',
            (string) $ticket->id,
            $tenantId,
            [
                'event_id' => $ticket->event_id,
                'guest_id' => $ticket->guest_id,
            ]
        );

        return response()->json([
            'data' => $this->formatTicket($ticket),
        ], 201);
    }

    /**
     * Display a specific ticket.
     */
    public function show(Request $request, string $ticketId): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $ticket = $this->locateTicket($request, $authUser, $ticketId);

        if ($ticket === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        return response()->json([
            'data' => $this->formatTicket($ticket),
        ]);
    }

    /**
     * Update an existing ticket.
     */
    public function update(TicketUpdateRequest $request, string $ticketId): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $ticket = $this->locateTicket($request, $authUser, $ticketId);

        if ($ticket === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $validated = $request->validated();

        if ($validated === []) {
            return response()->json([
                'data' => $this->formatTicket($ticket),
            ]);
        }

        if (
            $ticket->status === 'revoked'
            && array_key_exists('status', $validated)
            && $validated['status'] === 'used'
        ) {
            $this->throwValidationException([
                'status' => ['A revoked ticket cannot be marked as used.'],
            ]);
        }

        $seatData = [
            'seat_section' => array_key_exists('seat_section', $validated)
                ? $validated['seat_section']
                : $ticket->seat_section,
            'seat_row' => array_key_exists('seat_row', $validated)
                ? $validated['seat_row']
                : $ticket->seat_row,
            'seat_code' => array_key_exists('seat_code', $validated)
                ? $validated['seat_code']
                : $ticket->seat_code,
        ];

        $this->assertSeatAvailability((string) $ticket->event_id, $seatData, $ticket);

        $originalSnapshot = $this->ticketAuditSnapshot($ticket);
        $originalStatus = $ticket->status;
        $tenantId = (string) $ticket->event->tenant_id;

        $ticket->fill($validated);
        $ticket->save();
        $ticket->refresh();

        $updatedSnapshot = $this->ticketAuditSnapshot($ticket);
        $changes = $this->calculateDifferences($originalSnapshot, $updatedSnapshot);

        if ($changes !== []) {
            $this->recordAuditLog($authUser, $request, 'ticket', $ticket->id, 'updated', [
                'changes' => $changes,
            ], $tenantId);

            $this->logEntityLifecycle(
                $request,
                $authUser,
                'ticket',
                (string) $ticket->id,
                'updated',
                $tenantId,
                [
                    'event_id' => $ticket->event_id,
                    'guest_id' => $ticket->guest_id,
                    'changes' => $changes,
                ]
            );
        }

        if ($originalStatus !== 'revoked' && $ticket->status === 'revoked') {
            event(new TicketRevoked($ticket, $authUser, $request, $tenantId, $originalSnapshot, $updatedSnapshot, $changes));

            $this->logEntityLifecycle(
                $request,
                $authUser,
                'ticket',
                (string) $ticket->id,
                'revoked',
                $tenantId,
                [
                    'event_id' => $ticket->event_id,
                    'guest_id' => $ticket->guest_id,
                ]
            );

            $this->logLifecycleMetric(
                $request,
                $authUser,
                'tickets_revoked',
                'ticket',
                (string) $ticket->id,
                $tenantId,
                [
                    'event_id' => $ticket->event_id,
                    'guest_id' => $ticket->guest_id,
                ]
            );
        }

        return response()->json([
            'data' => $this->formatTicket($ticket),
        ]);
    }

    /**
     * Soft delete a ticket.
     */
    public function destroy(Request $request, string $ticketId): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $ticket = $this->locateTicket($request, $authUser, $ticketId);

        if ($ticket === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $tenantId = (string) $ticket->event->tenant_id;
        $originalSnapshot = $this->ticketAuditSnapshot($ticket);

        if ($ticket->status !== 'revoked') {
            $ticket->status = 'revoked';
            $ticket->save();
            $ticket->refresh();

            $revokedSnapshot = $this->ticketAuditSnapshot($ticket);
            $changes = $this->calculateDifferences($originalSnapshot, $revokedSnapshot);

            event(new TicketRevoked($ticket, $authUser, $request, $tenantId, $originalSnapshot, $revokedSnapshot, $changes));

            $this->logEntityLifecycle(
                $request,
                $authUser,
                'ticket',
                (string) $ticket->id,
                'revoked',
                $tenantId,
                [
                    'event_id' => $ticket->event_id,
                    'guest_id' => $ticket->guest_id,
                ]
            );

            $this->logLifecycleMetric(
                $request,
                $authUser,
                'tickets_revoked',
                'ticket',
                (string) $ticket->id,
                $tenantId,
                [
                    'event_id' => $ticket->event_id,
                    'guest_id' => $ticket->guest_id,
                ]
            );

            $originalSnapshot = $revokedSnapshot;
        }

        $ticket->delete();

        $this->recordAuditLog($authUser, $request, 'ticket', $ticket->id, 'deleted', [
            'before' => $originalSnapshot,
        ], $tenantId);

        $this->logEntityLifecycle(
            $request,
            $authUser,
            'ticket',
            (string) $ticket->id,
            'deleted',
            $tenantId,
            [
                'event_id' => $ticket->event_id,
                'guest_id' => $ticket->guest_id,
                'before' => $originalSnapshot,
            ]
        );

        return response()->json(null, 204);
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
     * Locate a ticket ensuring tenant constraints.
     */
    private function locateTicket(Request $request, User $authUser, string $ticketId): ?Ticket
    {
        $query = Ticket::query()->with(['event', 'guest'])->whereKey($ticketId);
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
     * Ensure seat assignments remain unique per event.
     *
     * @param  array{seat_section: ?string, seat_row: ?string, seat_code: ?string}  $seatData
     */
    private function assertSeatAvailability(string $eventId, array $seatData, ?Ticket $ignore): void
    {
        if (! $this->hasCompleteSeating($seatData)) {
            return;
        }

        $query = Ticket::withTrashed()
            ->where('event_id', $eventId)
            ->where('seat_section', $seatData['seat_section'])
            ->where('seat_row', $seatData['seat_row'])
            ->where('seat_code', $seatData['seat_code']);

        if ($ignore !== null) {
            $query->where('id', '!=', $ignore->id);
        }

        if ($query->exists()) {
            $this->throwValidationException([
                'seat_code' => ['The selected seat is already assigned to another ticket.'],
            ]);
        }
    }

    /**
     * Determine if the provided seat data is complete.
     *
     * @param  array{seat_section: ?string, seat_row: ?string, seat_code: ?string}  $seatData
     */
    private function hasCompleteSeating(array $seatData): bool
    {
        return $seatData['seat_section'] !== null
            && $seatData['seat_row'] !== null
            && $seatData['seat_code'] !== null;
    }

    /**
     * Format a ticket resource for API responses.
     *
     * @return array<string, mixed>
     */
    private function formatTicket(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'event_id' => $ticket->event_id,
            'guest_id' => $ticket->guest_id,
            'type' => $ticket->type,
            'price_cents' => $ticket->price_cents,
            'status' => $ticket->status,
            'seat_section' => $ticket->seat_section,
            'seat_row' => $ticket->seat_row,
            'seat_code' => $ticket->seat_code,
            'issued_at' => optional($ticket->issued_at)->toISOString(),
            'expires_at' => optional($ticket->expires_at)->toISOString(),
            'created_at' => optional($ticket->created_at)->toISOString(),
            'updated_at' => optional($ticket->updated_at)->toISOString(),
        ];
    }

    /**
     * Build an audit snapshot for the ticket.
     *
     * @return array<string, mixed>
     */
    private function ticketAuditSnapshot(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'event_id' => $ticket->event_id,
            'guest_id' => $ticket->guest_id,
            'type' => $ticket->type,
            'price_cents' => $ticket->price_cents,
            'status' => $ticket->status,
            'seat_section' => $ticket->seat_section,
            'seat_row' => $ticket->seat_row,
            'seat_code' => $ticket->seat_code,
            'issued_at' => optional($ticket->issued_at)->toISOString(),
            'expires_at' => optional($ticket->expires_at)->toISOString(),
            'deleted_at' => optional($ticket->deleted_at)->toISOString(),
        ];
    }
}
