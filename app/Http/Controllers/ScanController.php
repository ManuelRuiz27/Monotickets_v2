<?php

namespace App\Http\Controllers;

use App\Events\AttendanceCreated;
use App\Http\Controllers\Concerns\InteractsWithTenants;
use App\Models\Attendance;
use App\Models\Checkpoint;
use App\Models\Event;
use App\Models\HostessAssignment;
use App\Models\Qr;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit\RecordsAuditLogs;
use App\Support\Logging\StructuredLogging;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use function optional;

/**
 * Handle ticket scans for online and offline checkpoints.
 */
class ScanController extends Controller
{
    use InteractsWithTenants;
    use RecordsAuditLogs;
    use StructuredLogging;

    private const TRY_AGAIN_MESSAGE = 'try_again';

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

        $startedAt = microtime(true);

        try {
            $result = $this->handleScan($request, $authUser, $validated, false, $startedAt);
        } catch (HttpExceptionInterface $exception) {
            if ($this->isDatabaseTimeoutException($exception)) {
                return response()->json(['error' => self::TRY_AGAIN_MESSAGE], 503);
            }

            throw $exception;
        }

        if ($result === null) {
            $latencyMs = $this->calculateLatencyMs($startedAt);

            $this->logScanStructured(
                $request,
                $authUser,
                null,
                null,
                null,
                'invalid',
                $latencyMs,
                $validated['device_id'] ?? null,
                [
                    'qr_code' => (string) $validated['qr_code'],
                    'reason' => 'qr_not_found',
                ]
            );

            $tenantContext = $this->resolveTenantContext($request, $authUser) ?? 'unknown';

            $this->logLifecycleMetric(
                $request,
                $authUser,
                'scan_invalid',
                'scan',
                (string) $validated['qr_code'],
                (string) $tenantContext,
                [
                    'result' => 'invalid',
                    'reason' => 'qr_not_found',
                ]
            );

            return response()->json([
                'data' => $this->formatUnknownQrResponse((string) $validated['qr_code']),
            ]);
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

        $scans = [];

        foreach ($validated['scans'] as $index => $scan) {
            $scans[] = [
                'index' => $index,
                'payload' => $scan,
            ];
        }

        usort($scans, static function (array $left, array $right): int {
            $leftTime = CarbonImmutable::parse((string) $left['payload']['scanned_at']);
            $rightTime = CarbonImmutable::parse((string) $right['payload']['scanned_at']);

            return $leftTime <=> $rightTime;
        });

        $responses = [];
        $summary = [
            'valid' => 0,
            'duplicate' => 0,
            'errors' => 0,
        ];

        foreach ($scans as $scan) {
            $index = $scan['index'];
            $payload = $scan['payload'];
            $startedAt = microtime(true);

            try {
                $result = $this->handleScan($request, $authUser, $payload, true, $startedAt);
            } catch (HttpExceptionInterface $exception) {
                if ($this->isDatabaseTimeoutException($exception)) {
                    return response()->json(['error' => self::TRY_AGAIN_MESSAGE], 503);
                }

                $responses[] = [
                    'index' => $index,
                    'status' => $exception->getStatusCode(),
                    'result' => 'error',
                    'message' => $exception->getMessage(),
                    'reason' => 'hostess_assignment_missing',
                ];
                ++$summary['errors'];

                continue;
            }

            if ($result === null) {
                $response = array_merge([
                    'index' => $index,
                ], $this->formatUnknownQrResponse((string) $payload['qr_code']));

                $responses[] = $response;
                ++$summary['errors'];

                $latencyMs = $this->calculateLatencyMs($startedAt);
                $this->logScanStructured(
                    $request,
                    $authUser,
                    null,
                    null,
                    null,
                    'invalid',
                    $latencyMs,
                    $payload['device_id'] ?? null,
                    [
                        'qr_code' => (string) $payload['qr_code'],
                        'reason' => 'qr_not_found',
                        'batch_index' => $index,
                    ]
                );

                $tenantContext = $this->resolveTenantContext($request, $authUser) ?? 'unknown';

                $this->logLifecycleMetric(
                    $request,
                    $authUser,
                    'scan_invalid',
                    'scan',
                    (string) $payload['qr_code'],
                    (string) $tenantContext,
                    [
                        'result' => 'invalid',
                        'reason' => 'qr_not_found',
                    ]
                );

                continue;
            }

            $responses[] = array_merge(['index' => $index], $result);

            if ($result['result'] === 'valid') {
                ++$summary['valid'];
            } elseif ($result['result'] === 'duplicate') {
                ++$summary['duplicate'];
            } else {
                ++$summary['errors'];
            }
        }

        $tenantContext = $this->resolveTenantContext($request, $authUser);

        $this->recordAuditLog(
            $authUser,
            $request,
            'scan_sync',
            (string) $authUser->id,
            'sync_batch_uploaded',
            [
                'summary' => $summary,
                'total_scans' => count($responses),
            ],
            $tenantContext
        );

        return response()->json([
            'data' => $responses,
            'meta' => [
                'summary' => $summary,
                'total_scans' => count($responses),
            ],
        ], 207);
    }

    /**
     * Handle the scan workflow, returning the API payload for the client.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function handleScan(Request $request, User $authUser, array $payload, bool $forceOffline, float $startedAt): ?array
    {
        $scanTime = CarbonImmutable::parse((string) $payload['scanned_at']);
        $offline = $forceOffline ? true : (bool) ($payload['offline'] ?? false);
        $qrCode = (string) $payload['qr_code'];
        $deviceId = isset($payload['device_id']) ? (string) $payload['device_id'] : null;
        $checkpointId = isset($payload['checkpoint_id']) ? (string) $payload['checkpoint_id'] : null;
        $eventHint = isset($payload['event_id']) ? (string) $payload['event_id'] : null;

        $ticket = null;
        $event = null;
        $checkpoint = null;

        try {
            $qr = Qr::query()
                ->with(['ticket' => function ($query): void {
                    $query->with(['event', 'guest']);
                }])
                ->where('code', $qrCode)
                ->first();

            $this->ensureDatabaseWithinTimeout($startedAt);

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
                    ],
                    $startedAt
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
                    ],
                    $startedAt
                );
            }

            if ($checkpointId !== null) {
                $checkpoint = Checkpoint::query()->whereKey($checkpointId)->first();

                $this->ensureDatabaseWithinTimeout($startedAt);

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
                        ],
                        $startedAt
                    );
                }
            }

            if (! $this->hostessHasActiveAssignment($authUser, $event, $checkpoint, $scanTime)) {
                $this->logScanFailure($request, $authUser, $payload, $event, $ticket, $checkpoint, $startedAt, 'hostess_assignment_missing', 403);
                $this->recordScanErrorMetric($request, $authUser, $payload, $event);

                throw new HttpException(403, 'The hostess is not assigned to this event or checkpoint.');
            }

            $this->ensureDatabaseWithinTimeout($startedAt);

            if ($existingAttendance = $this->findExistingAttendance($ticket, $event, $scanTime, $deviceId)) {
                $this->ensureDatabaseWithinTimeout($startedAt);

                return $this->buildResponseFromAttendance(
                    $request,
                    $authUser,
                    $ticket,
                    $event,
                    $qr,
                    $existingAttendance,
                    $startedAt,
                    $offline,
                    $deviceId,
                    $checkpoint
                );
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
                    ],
                    $startedAt
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
                    ],
                    $startedAt
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
                    ],
                    $startedAt
                );
            }

            $event->loadMissing('attendances');

            $this->ensureDatabaseWithinTimeout($startedAt);

            $lastValidAttendance = Attendance::query()
                ->where('ticket_id', $ticket->id)
                ->where('result', 'valid')
                ->latest('scanned_at')
                ->first();

            $this->ensureDatabaseWithinTimeout($startedAt);

            $hasValidAttendance = $lastValidAttendance !== null;
            $isDuplicate = $event->checkin_policy === 'single' && ($hasValidAttendance || $ticket->status === 'used');

            if ($isDuplicate) {
                $metadata = [
                    'reason' => 'duplicate_entry',
                ];

                if ($lastValidAttendance !== null && $lastValidAttendance->scanned_at !== null) {
                    $lastValidAt = CarbonImmutable::parse($lastValidAttendance->scanned_at->toIso8601String());
                    $secondsSinceLastValid = $scanTime->diffInRealSeconds($lastValidAt);

                    if ($secondsSinceLastValid < $this->duplicateGraceSeconds()) {
                        $metadata['last_validated_at'] = $lastValidAt->toIso8601String();
                    }
                }

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
                    $metadata,
                    $startedAt
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
                ],
                $startedAt
            );
        } catch (HttpExceptionInterface $exception) {
            if ($this->isDatabaseTimeoutException($exception)) {
                $this->logScanFailure($request, $authUser, $payload, $event, $ticket, $checkpoint, $startedAt, self::TRY_AGAIN_MESSAGE, $exception->getStatusCode());
                $this->recordScanErrorMetric($request, $authUser, $payload, $event);
            }

            throw $exception;
        }
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
        array $metadata,
        float $startedAt
    ): array {
        $outcome = $this->db->transaction(function () use (
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
            $metadata,
            $startedAt
        ): array {
            $this->ensureDatabaseWithinTimeout($startedAt);

            $ticket = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

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

            $this->ensureDatabaseWithinTimeout($startedAt);

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
                'metadata_json' => array_filter(array_merge($metadata, [
                    'message' => $message,
                ]), static fn ($value) => $value !== null),
            ]);

            $this->ensureDatabaseWithinTimeout($startedAt);

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

            $this->ensureDatabaseWithinTimeout($startedAt);

            $this->logEntityLifecycle(
                $request,
                $authUser,
                'ticket',
                (string) $ticket->id,
                $action,
                $tenantId,
                [
                    'event_id' => $event->id,
                    'guest_id' => $ticket->guest_id,
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
                (string) $ticket->id,
                $tenantId,
                [
                    'event_id' => $event->id,
                    'guest_id' => $ticket->guest_id,
                'result' => $result,
            ]
            );

            $latencyMs = $this->calculateLatencyMs($startedAt);

            $this->logScanStructured(
                $request,
                $authUser,
                $event,
                $ticket,
                $checkpoint,
                $result,
                $latencyMs,
                $deviceId,
                [
                    'qr_code' => $qr->code,
                    'message' => $message,
                    'offline' => $offline,
                ]
            );

            return [
                'payload' => [
                    'result' => $result,
                    'message' => $message,
                    'reason' => $metadata['reason'] ?? null,
                    'qr_code' => $qr->code,
                    'ticket' => $this->formatTicket($ticket->fresh(['guest', 'event'])),
                    'attendance' => $this->formatAttendance($attendance->fresh(['checkpoint'])),
                    'last_validated_at' => $metadata['last_validated_at'] ?? null,
                ],
                'attendance' => $attendance,
            ];
        });

        /** @var Attendance $createdAttendance */
        $createdAttendance = $outcome['attendance'];

        event(new AttendanceCreated((string) $event->id, (string) $createdAttendance->id, (string) $event->tenant_id));

        event('attendance.created', [
            'event_id' => (string) $event->id,
            'attendance_id' => (string) $createdAttendance->id,
            'tenant_id' => (string) $event->tenant_id,
        ]);

        return $outcome['payload'];
    }

    /**
     * Determine if the hostess has an active assignment for the event and checkpoint.
     */
    private function hostessHasActiveAssignment(User $authUser, Event $event, ?Checkpoint $checkpoint, CarbonImmutable $scanTime): bool
    {
        $now = Carbon::parse($scanTime->toIso8601String());

        $venueId = $checkpoint?->venue_id;

        return HostessAssignment::query()
            ->forTenant((string) $event->tenant_id)
            ->where('hostess_user_id', $authUser->id)
            ->where('event_id', $event->id)
            ->when($checkpoint !== null, function ($query) use ($checkpoint, $venueId): void {
                $query->where(function ($constraint) use ($checkpoint, $venueId): void {
                    $constraint->where('checkpoint_id', $checkpoint->id);

                    $constraint->orWhere(function ($assignment) use ($venueId): void {
                        $assignment->whereNull('checkpoint_id')
                            ->where(function ($venueScope) use ($venueId): void {
                                $venueScope->whereNull('venue_id');

                                if ($venueId !== null) {
                                    $venueScope->orWhere('venue_id', $venueId);
                                }
                            });
                    });
                });
            }, function ($query): void {
                $query
                    ->whereNull('checkpoint_id')
                    ->whereNull('venue_id');
            })
            ->currentlyActive($now)
            ->exists();
    }

    /**
     * Attempt to reuse an existing attendance record when the scan is idempotent.
     */
    private function findExistingAttendance(Ticket $ticket, Event $event, CarbonImmutable $scanTime, ?string $deviceId): ?Attendance
    {
        $windowSeconds = $this->idempotencyWindowSeconds();
        $windowStart = $scanTime->subSeconds($windowSeconds);
        $windowEnd = $scanTime->addSeconds($windowSeconds);

        $query = Attendance::query()
            ->where('event_id', $event->id)
            ->where('ticket_id', $ticket->id)
            ->whereBetween('scanned_at', [$windowStart, $windowEnd])
            ->orderByDesc('scanned_at');

        if ($deviceId !== null) {
            $query->where('device_id', $deviceId);
        } else {
            $query->whereNull('device_id');
        }

        return $query->first();
    }

    /**
     * Build the response payload based on an existing attendance record.
     *
     * @return array<string, mixed>
     */
    private function buildResponseFromAttendance(
        Request $request,
        User $authUser,
        Ticket $ticket,
        Event $event,
        Qr $qr,
        Attendance $attendance,
        float $startedAt,
        bool $offline,
        ?string $deviceId,
        ?Checkpoint $checkpoint
    ): array
    {
        $metadata = $attendance->metadata_json ?? [];
        $attendance = $attendance->fresh(['checkpoint']);
        $ticket = $ticket->fresh(['guest', 'event']);

        $response = [
            'result' => $attendance->result,
            'message' => $metadata['message'] ?? $this->messageForResult($attendance->result, $metadata['reason'] ?? null),
            'reason' => $metadata['reason'] ?? null,
            'qr_code' => $qr->code,
            'ticket' => $this->formatTicket($ticket),
            'attendance' => $this->formatAttendance($attendance),
            'last_validated_at' => $metadata['last_validated_at'] ?? null,
        ];

        $latencyMs = $this->calculateLatencyMs($startedAt);
        $resolvedDevice = $attendance->device_id ?? $deviceId;
        $resolvedCheckpoint = $attendance->checkpoint ?? $checkpoint;

        $this->logScanStructured(
            $request,
            $authUser,
            $event,
            $ticket,
            $resolvedCheckpoint,
            $attendance->result,
            $latencyMs,
            $resolvedDevice,
            [
                'qr_code' => $qr->code,
                'message' => $response['message'],
                'offline' => $attendance->offline ?? $offline,
                'idempotent_reuse' => true,
            ]
        );

        return $response;
    }

    private function isDatabaseTimeoutException(HttpExceptionInterface $exception): bool
    {
        return $exception->getStatusCode() === 503 && $exception->getMessage() === self::TRY_AGAIN_MESSAGE;
    }

    /**
     * Resolve the human-readable message associated with a scan result.
     */
    private function messageForResult(string $result, ?string $reason = null): string
    {
        return match ($result) {
            'valid' => 'The ticket is valid.',
            'duplicate' => 'The ticket has already been used.',
            'revoked' => 'The ticket has been revoked.',
            'expired' => 'The ticket has expired.',
            'invalid' => match ($reason) {
                'tenant_mismatch' => 'The ticket does not belong to the active tenant.',
                'event_mismatch' => 'The ticket belongs to a different event.',
                'checkpoint_invalid' => 'The checkpoint is not valid for this event.',
                'qr_inactive' => 'The QR code is inactive.',
                default => 'The QR code could not be resolved.',
            },
            default => 'The ticket could not be processed.',
        };
    }

    private function idempotencyWindowSeconds(): int
    {
        return (int) config('scan.idempotency_window_seconds', 5);
    }

    private function duplicateGraceSeconds(): int
    {
        return (int) config('scan.duplicate_grace_seconds', 10);
    }

    private function databaseTimeoutMilliseconds(): int
    {
        return (int) config('scan.database_timeout_ms', 0);
    }

    private function ensureDatabaseWithinTimeout(float $startedAt): void
    {
        $timeoutMs = $this->databaseTimeoutMilliseconds();

        if ($timeoutMs <= 0) {
            return;
        }

        if ($this->calculateLatencyMs($startedAt) > $timeoutMs) {
            throw new HttpException(503, self::TRY_AGAIN_MESSAGE);
        }
    }

    private function calculateLatencyMs(float $startedAt): int
    {
        return (int) max(0, round((microtime(true) - $startedAt) * 1000));
    }

    /**
     * Emit the structured log entry required for every scan attempt.
     *
     * @param array<string, mixed> $context
     */
    private function logScanStructured(
        Request $request,
        User $authUser,
        ?Event $event,
        ?Ticket $ticket,
        ?Checkpoint $checkpoint,
        string $result,
        int $latencyMs,
        ?string $deviceId,
        array $context = []
    ): void {
        $baseContext = array_filter(array_merge([
            'tenant_id' => $event?->tenant_id,
            'event_id' => $event?->id,
            'checkpoint_id' => $checkpoint?->id,
            'ticket_id' => $ticket?->id,
            'guest_id' => $ticket?->guest_id,
            'result' => $result,
            'latency_ms' => $latencyMs,
            'device_id' => $deviceId,
            'hostess_user_id' => $authUser->id,
        ], $context), static fn ($value) => $value !== null);

        if (isset($baseContext['tenant_id'])) {
            Log::info('metrics.distribution', array_filter([
                'metric' => 'tenant_scan_qps',
                'tenant_id' => (string) $baseContext['tenant_id'],
                'event_id' => $baseContext['event_id'] ?? null,
                'value' => 1,
            ], static fn ($value) => $value !== null));
        }

        $this->logStructuredEvent($request, 'scan.processed', $baseContext);
    }

    /**
     * Log an error outcome for scans that did not complete successfully.
     *
     * @param array<string, mixed> $payload
     */
    private function logScanFailure(
        Request $request,
        User $authUser,
        array $payload,
        ?Event $event,
        ?Ticket $ticket,
        ?Checkpoint $checkpoint,
        float $startedAt,
        string $reason,
        ?int $status = null
    ): void {
        $context = [
            'reason' => $reason,
            'status' => $status,
            'qr_code' => isset($payload['qr_code']) ? (string) $payload['qr_code'] : null,
            'offline' => isset($payload['offline']) ? (bool) $payload['offline'] : null,
            'event_hint' => isset($payload['event_id']) ? (string) $payload['event_id'] : null,
        ];

        if ($status === null) {
            unset($context['status']);
        }

        $latencyMs = $this->calculateLatencyMs($startedAt);

        $this->logScanStructured(
            $request,
            $authUser,
            $event,
            $ticket,
            $checkpoint,
            'error',
            $latencyMs,
            isset($payload['device_id']) ? (string) $payload['device_id'] : null,
            $context
        );
    }

    /**
     * Record the scan_error metric for failed scans.
     *
     * @param array<string, mixed> $payload
     */
    private function recordScanErrorMetric(
        Request $request,
        User $authUser,
        array $payload,
        ?Event $event = null
    ): void {
        $tenantContext = $event?->tenant_id ?? $this->resolveTenantContext($request, $authUser) ?? 'unknown';
        $entityId = isset($payload['qr_code']) ? (string) $payload['qr_code'] : 'unknown';

        $this->logLifecycleMetric(
            $request,
            $authUser,
            'scan_error',
            'scan',
            $entityId,
            (string) $tenantContext,
            array_filter([
                'result' => 'error',
                'event_id' => $event?->id ?? ($payload['event_id'] ?? null),
            ], static fn ($value) => $value !== null)
        );
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

    /**
     * Build the response for scans where the QR code cannot be resolved.
     *
     * @return array<string, mixed>
     */
    private function formatUnknownQrResponse(string $qrCode): array
    {
        return [
            'result' => 'invalid',
            'reason' => 'qr_not_found',
            'message' => 'The QR code could not be resolved.',
            'qr_code' => $qrCode,
            'ticket' => null,
            'attendance' => null,
        ];
    }
}
