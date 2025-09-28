<?php

namespace App\Http\Controllers;

use App\Events\QrRotated;
use App\Http\Controllers\Concerns\InteractsWithTenants;
use App\Models\Qr;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Qr\InternalQrCodeProvider;
use App\Services\Qr\QrCodeProvider;
use App\Support\ApiResponse;
use App\Support\Logging\StructuredLogging;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use function event;
use function optional;

/**
 * Manage QR codes associated with tickets.
 */
class QrController extends Controller
{
    use InteractsWithTenants;
    use StructuredLogging;

    private QrCodeProvider $qrCodeProvider;

    public function __construct(InternalQrCodeProvider $qrCodeProvider)
    {
        $this->qrCodeProvider = $qrCodeProvider;
    }

    /**
     * Retrieve the QR code for the specified ticket.
     */
    public function show(Request $request, string $ticketId): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $ticket = $this->locateTicket($request, $authUser, $ticketId);

        if ($ticket === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $qr = $ticket->qr;

        if ($qr === null) {
            return ApiResponse::error('NOT_FOUND', 'No QR code is associated with this ticket.', null, 404);
        }

        return response()->json([
            'data' => $this->formatQr($qr),
        ]);
    }

    /**
     * Create or rotate the QR code for the specified ticket.
     */
    public function store(Request $request, string $ticketId): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $ticket = $this->locateTicket($request, $authUser, $ticketId);

        if ($ticket === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        if ($ticket->status !== 'issued') {
            $this->throwValidationException([
                'ticket_id' => ['The ticket must be issued and active to rotate its QR code.'],
            ]);
        }

        $qr = $ticket->qr()->first();
        $wasCreated = $qr === null;
        $originalSnapshot = $qr !== null ? $this->formatQr($qr) : null;

        if ($qr === null) {
            $qr = new Qr();
            $qr->ticket_id = $ticket->id;
            $qr->version = 0;
        }

        $qr->code = $this->qrCodeProvider->generate($ticket);
        $qr->version = (int) $qr->version + 1;
        $qr->is_active = true;
        $qr->save();
        $qr->refresh();

        $updatedSnapshot = $this->formatQr($qr);
        $changes = $this->calculateDifferences($originalSnapshot, $updatedSnapshot);
        $tenantId = (string) $ticket->event->tenant_id;

        event(new QrRotated(
            $ticket,
            $qr,
            $authUser,
            $request,
            $tenantId,
            $originalSnapshot,
            $updatedSnapshot,
            $changes
        ));

        $this->logEntityLifecycle(
            $request,
            $authUser,
            'qr',
            (string) $qr->id,
            'rotated',
            $tenantId,
            [
                'ticket_id' => $ticket->id,
                'version' => $qr->version,
            ]
        );

        return response()->json([
            'data' => $updatedSnapshot,
        ], $wasCreated ? 201 : 200);
    }

    /**
     * Locate a ticket ensuring tenant constraints.
     */
    private function locateTicket(Request $request, User $authUser, string $ticketId): ?Ticket
    {
        $query = Ticket::query()->with(['event', 'guest', 'qr'])->whereKey($ticketId);
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
     * Format the QR model for API responses.
     *
     * @return array<string, mixed>
     */
    private function formatQr(Qr $qr): array
    {
        return [
            'id' => $qr->id,
            'ticket_id' => $qr->ticket_id,
            'code' => $qr->code,
            'version' => $qr->version,
            'is_active' => $qr->is_active,
            'created_at' => optional($qr->created_at)->toISOString(),
            'updated_at' => optional($qr->updated_at)->toISOString(),
        ];
    }

    /**
     * Calculate differences between two QR snapshots.
     *
     * @param  array<string, mixed>|null  $original
     * @param  array<string, mixed>  $updated
     * @return array<string, array<string, mixed>>
     */
    private function calculateDifferences(?array $original, array $updated): array
    {
        if ($original === null) {
            return [];
        }

        $changes = [];

        foreach ($updated as $key => $value) {
            if (! array_key_exists($key, $original)) {
                continue;
            }

            if ($original[$key] === $value) {
                continue;
            }

            $changes[$key] = [
                'before' => $original[$key],
                'after' => $value,
            ];
        }

        return $changes;
    }
}
