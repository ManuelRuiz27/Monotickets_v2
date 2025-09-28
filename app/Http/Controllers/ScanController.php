<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithTenants;
use App\Models\Attendance;
use App\Models\Checkpoint;
use App\Models\Event;
use App\Models\Qr;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Audit\RecordsAuditLogs;
use App\Support\Logging\StructuredLogging;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use function optional;

/**
 * Handle ticket scans for online and offline checkpoints.
 */
class ScanController extends Controller
{
    use InteractsWithTenants;
    use RecordsAuditLogs;
    use StructuredLogging;

    private DatabaseManager $db;

    public function __construct(DatabaseManager $db)
    {
        $this->db = $db;
    }

    /**
     * Process a single online scan request.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();

        $validated = $this->validate($request, [
            'qr_code' => ['required', 'string'],
            'checkpoint_id' => ['nullable', 'uuid'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'scanned_at' => ['required', 'date'],
            'offline' => ['sometimes', 'boolean'],
            'event_id' => ['nullable', 'uuid'],
        ]);

        $result = $this->handleScan($request, $authUser, $validated, false);

        if ($result === null) {
            return ApiResponse::error('INVALID_QR', 'The QR code could not be resolved.', null, 404);
        }

        return response()->json([
            'data' => $result,
        ]);
    }

    /**
     * Process a batch of offline scans.
     */
    public function batch(Request $request): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();

        $validated = $this->validate($request, [
            'scans' => ['required', 'array', 'min:1'],
            'scans.*.qr_code' => ['required', 'string'],
            'scans.*.checkpoint_id' => ['nullable', 'uuid'],
            'scans.*.device_id' => ['nullable', 'string', 'max:255'],
            'scans.*.scanned_at' => ['required', 'date'],
            'scans.*.offline' => ['sometimes', 'boolean'],
            'scans.*.event_id' => ['nullable', 'uuid'],
        ]);

        $responses = [];

        foreach ($validated['scans'] as $index => $scan) {
            $payload = $this->handleScan($request, $authUser, $scan, true);

            if ($payload === null) {
                $responses[] = [
                    'index' => $index,
                    'result' => 'invalid',
                    'reason' => 'qr_not_found',
                    'message' => 'The QR code could not be resolved.',
                    'qr_code' => (string) $scan['qr_code'],
                    'ticket' => null,
                    'attendance' => null,
                ];

                continue;
            }

            $responses[] = array_merge(['index' => $index], $payload);
        }

        return response()->json([
            'data' => $responses,
        ], 207);
    }

    /**
     * Handle the scan workflow, returning the API payload for the client.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function handleScan(Request $request, User $authUser, array $payload, bool $forceOffline): ?array
    {
        $scanTime = CarbonImmutable::parse((string) $payload['scanned_at']);
        $offline = $forceOffline ? true : (bool) ($payload['offline'] ?? false);
        $qrCode = (string) $payload['qr_code'];
        $deviceId = isset($payload['device_id']) ? (string) $payload['device_id'] : null;
        $checkpointId = isset($payload['checkpoint_id']) ? (string) $payload['checkpoint_id'] : null;
        $eventHint = isset($payload['event_id']) ? (string) $payload['event_id'] : null;

        $qr = Qr::query()
            ->with(['ticket' => function ($query): void {
                $query->with(['event', 'guest']);
            }])
            ->where('code', $qrCode)
            ->first();

        if ($qr === null || $qr->ticket === null || $qr->ticket->event === null) {
            return null;
        }

        $ticket = $qr->ticket;
        $event = $ticket->event;
        $tenantId = (string) $event->tenant_id;
        $tenantContext = $this->resolveTenantContext($request, $authUser);

        if ($tenantContext !== null && $tenantContext !== $tenantId && ! $this->isSuperAdmin($authUser)) {
            return $this->finalizeScan(
                $request,
                $authUser,
                $ticket,
                $event,
                $qr,
                'invalid',
                'The ticket does not belong to the active tenant.',
                $scanTime,
                $offline,
                $deviceId,
                null,
                [
                    'reason' => 'tenant_mismatch',
                    'provided_event_id' => $eventHint,
                ]
            );
        }

        if ($eventHint !== null && $eventHint !== $event->id) {
            return $this->finalizeScan(
                $request,
                $authUser,
                $ticket,
                $event,
                $qr,
                'invalid',
                'The ticket belongs to a different event.',
                $scanTime,
                $offline,
                $deviceId,
                null,
                [
                    'reason' => 'event_mismatch',
                    'provided_event_id' => $eventHint,
                ]
            );
        }

        $checkpoint = null;

        if ($checkpointId !== null) {
            $checkpoint = Checkpoint::query()->whereKey($checkpointId)->first();

            if ($checkpoint === null || $checkpoint->event_id !== $event->id) {
                return $this->finalizeScan(
                    $request,
                    $authUser,
                    $ticket,
                    $event,
                    $qr,
                    'invalid',
                    'The checkpoint is not valid for this event.',
                    $scanTime,
                    $offline,
                    $deviceId,
                    null,
                    [
                        'reason' => 'checkpoint_invalid',
                        'provided_checkpoint_id' => $checkpointId,
                    ]
                );
            }
        }

        if (! $qr->is_active) {
            return $this->finalizeScan(
                $request,
                $authUser,
                $ticket,
                $event,
                $qr,
                'invalid',
                'The QR code is inactive.',
                $scanTime,
                $offline,
                $deviceId,
                $checkpoint,
                [
                    'reason' => 'qr_inactive',
                ]
            );
        }

        if ($ticket->status === 'revoked') {
            return $this->finalizeScan(
                $request,
                $authUser,
                $ticket,
                $event,
                $qr,
                'revoked',
                'The ticket has been revoked.',
                $scanTime,
                $offline,
                $deviceId,
                $checkpoint,
                [
                    'reason' => 'ticket_revoked',
                ]
            );
        }

        $isExpired = $ticket->status === 'expired';

        if ($ticket->expires_at !== null && $scanTime->greaterThan($ticket->expires_at)) {
            $isExpired = true;
        }

        if ($isExpired) {
            return $this->finalizeScan(
                $request,
                $authUser,
                $ticket,
                $event,
                $qr,
                'expired',
                'The ticket has expired.',
                $scanTime,
                $offline,
                $deviceId,
                $checkpoint,
                [
                    'reason' => 'ticket_expired',
                ]
            );
        }

        $event->loadMissing('attendances');

        $hasValidAttendance = Attendance::query()
            ->where('ticket_id', $ticket->id)
            ->where('result', 'valid')
            ->exists();

        $isDuplicate = $event->checkin_policy === 'single' && ($hasValidAttendance || $ticket->status === 'used');

        if ($isDuplicate) {
            return $this->finalizeScan(
                $request,
                $authUser,
                $ticket,
                $event,
                $qr,
                'duplicate',
                'The ticket has already been used.',
                $scanTime,
                $offline,
                $deviceId,
                $checkpoint,
                [
                    'reason' => 'duplicate_entry',
                ]
            );
        }

        return $this->finalizeScan(
            $request,
            $authUser,
            $ticket,
            $event,
            $qr,
            'valid',
            'The ticket is valid.',
            $scanTime,
            $offline,
            $deviceId,
            $checkpoint,
            [
                'reason' => 'accepted',
            ]
        );
    }

    /**
     * Persist the scan outcome and build the response payload.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function finalizeScan(
        Request $request,
        User $authUser,
        Ticket $ticket,
        Event $event,
        Qr $qr,
        string $result,
        string $message,
        CarbonImmutable $scannedAt,
        bool $offline,
        ?string $deviceId,
        ?Checkpoint $checkpoint,
        array $metadata
    ): array {
        return $this->db->transaction(function () use (
            $request,
            $authUser,
            $ticket,
            $event,
            $qr,
            $result,
            $message,
            $scannedAt,
            $offline,
            $deviceId,
            $checkpoint,
            $metadata
        ): array {
            if ($result === 'valid' && $ticket->status !== 'used') {
                $ticket->status = 'used';
                $ticket->save();
                $ticket->refresh();
            }

            if ($result === 'expired' && $ticket->status !== 'expired') {
                $ticket->status = 'expired';
                $ticket->save();
                $ticket->refresh();
            }

            $attendance = Attendance::query()->create([
                'event_id' => $event->id,
                'ticket_id' => $ticket->id,
                'guest_id' => $ticket->guest_id,
                'checkpoint_id' => $checkpoint?->id,
                'hostess_user_id' => $authUser->id,
                'result' => $result,
                'scanned_at' => $scannedAt,
                'device_id' => $deviceId,
                'offline' => $offline,
                'metadata_json' => array_filter($metadata, static fn ($value) => $value !== null),
            ]);

            $tenantId = (string) $event->tenant_id;
            $action = sprintf('scan_%s', $result);

            $this->recordAuditLog($authUser, $request, 'ticket', $ticket->id, $action, [
                'scan_result' => $result,
                'scan_time' => $scannedAt->toIso8601String(),
                'checkpoint_id' => $checkpoint?->id,
                'device_id' => $deviceId,
                'offline' => $offline,
                'metadata' => $metadata,
            ], $tenantId);

            $this->logEntityLifecycle(
                $request,
                $authUser,
                'ticket',
                $ticket->id,
                $action,
                $tenantId,
                [
                    'result' => $result,
                    'attendance_id' => $attendance->id,
                    'checkpoint_id' => $checkpoint?->id,
                    'offline' => $offline,
                ]
            );

            $this->logLifecycleMetric(
                $request,
                $authUser,
                $action,
                'ticket',
                $ticket->id,
                $tenantId,
                [
                    'result' => $result,
                ]
            );

            return [
                'result' => $result,
                'message' => $message,
                'reason' => $metadata['reason'] ?? null,
                'qr_code' => $qr->code,
                'ticket' => $this->formatTicket($ticket->fresh(['guest', 'event'])),
                'attendance' => $this->formatAttendance($attendance->fresh(['checkpoint'])),
            ];
        });
    }

    /**
     * Format the ticket details for the API response.
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

    /**
     * Format the attendance details for responses.
     *
     * @return array<string, mixed>
     */
    private function formatAttendance(Attendance $attendance): array
    {
        return [
            'id' => $attendance->id,
            'result' => $attendance->result,
            'checkpoint_id' => $attendance->checkpoint_id,
            'hostess_user_id' => $attendance->hostess_user_id,
            'scanned_at' => optional($attendance->scanned_at)->toISOString(),
            'device_id' => $attendance->device_id,
            'offline' => $attendance->offline,
            'metadata' => $attendance->metadata_json,
        ];
    }
}
