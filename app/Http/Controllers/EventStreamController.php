<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithTenants;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\HostessAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use JsonException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Provide server-sent events with live attendance summaries for events.
 */
class EventStreamController extends Controller
{
    use InteractsWithTenants;

    private const HEARTBEAT_COMMENT = 'heartbeat';

    /**
     * Emit attendance summaries for an event over Server-Sent Events.
     */
    public function stream(Request $request, string $event_id): StreamedResponse
    {
        $eventId = $event_id;
        /** @var User $authUser */
        $authUser = $request->user();
        $event = $this->locateEvent($request, $authUser, $eventId);

        if ($event === null) {
            abort(404, 'The requested resource was not found.');
        }

        $this->assertCanAccessEvent($authUser, $event);

        $validated = $this->validate($request, [
            'interval' => ['sometimes', 'integer', 'min:1', 'max:60'],
        ]);

        $interval = (int) ($validated['interval'] ?? 5);

        return response()->stream(function () use ($event, $interval): void {
            $this->streamEventSummaries($event, $interval);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Stream attendance summaries for the provided event.
     */
    private function streamEventSummaries(Event $event, int $interval): void
    {
        if (app()->environment('testing')) {
            $latestChange = $this->latestAttendanceChange($event);
            $payload = $this->buildSummaryPayload($event, $latestChange);
            $this->emitPayload($payload);

            return;
        }

        set_time_limit(0);
        $lastChange = $this->latestAttendanceChange($event);
        $payload = $this->buildSummaryPayload($event, $lastChange);
        $this->emitPayload($payload);

        $nextHeartbeatAt = microtime(true) + $interval;

        while (connection_aborted() === 0) {
            usleep(1_000_000);

            $currentChange = $this->latestAttendanceChange($event);

            if ($currentChange?->ne($lastChange) ?? ($lastChange !== null)) {
                $lastChange = $currentChange;
                $payload = $this->buildSummaryPayload($event, $lastChange);
                $this->emitPayload($payload);
                $nextHeartbeatAt = microtime(true) + $interval;

                continue;
            }

            if (microtime(true) >= $nextHeartbeatAt) {
                $this->emitHeartbeat();
                $nextHeartbeatAt = microtime(true) + $interval;
            }
        }
    }

    /**
     * Build the payload emitted to clients.
     *
     * @return array<string, mixed>
     */
    private function buildSummaryPayload(Event $event, ?CarbonImmutable $lastChange): array
    {
        $summary = $this->buildAttendanceSummary($event);

        return [
            'event_id' => (string) $event->id,
            'generated_at' => CarbonImmutable::now()->toISOString(),
            'last_change_at' => $lastChange?->toISOString(),
            'totals' => $summary['totals'],
            'checkpoints' => $summary['checkpoints'],
        ];
    }

    /**
     * Aggregate attendance counts for the event.
     *
     * @return array{totals: array{valid:int, duplicate:int, invalid:int}, checkpoints: array<int, array{checkpoint_id: ?string, valid:int, duplicate:int, invalid:int}>}
     */
    private function buildAttendanceSummary(Event $event): array
    {
        $totals = [
            'valid' => 0,
            'duplicate' => 0,
            'invalid' => 0,
        ];

        $checkpointSummaries = [];

        $rows = Attendance::query()
            ->select('checkpoint_id', 'result', DB::raw('count(*) as aggregate'))
            ->where('event_id', $event->id)
            ->groupBy('checkpoint_id', 'result')
            ->get();

        foreach ($rows as $row) {
            $bucket = $this->bucketForResult((string) $row->result);

            if ($bucket === null) {
                continue;
            }

            $count = (int) $row->aggregate;
            $totals[$bucket] += $count;

            $checkpointKey = $row->checkpoint_id ?? '__unassigned__';

            if (! array_key_exists($checkpointKey, $checkpointSummaries)) {
                $checkpointSummaries[$checkpointKey] = [
                    'checkpoint_id' => $row->checkpoint_id !== null ? (string) $row->checkpoint_id : null,
                    'valid' => 0,
                    'duplicate' => 0,
                    'invalid' => 0,
                ];
            }

            $checkpointSummaries[$checkpointKey][$bucket] += $count;
        }

        $checkpoints = array_values($checkpointSummaries);

        usort($checkpoints, function (array $left, array $right): int {
            $leftId = $left['checkpoint_id'] ?? '';
            $rightId = $right['checkpoint_id'] ?? '';

            return strcmp($leftId, $rightId);
        });

        return [
            'totals' => $totals,
            'checkpoints' => $checkpoints,
        ];
    }

    /**
     * Determine the summary bucket for a scan result.
     */
    private function bucketForResult(string $result): ?string
    {
        return match ($result) {
            'valid' => 'valid',
            'duplicate' => 'duplicate',
            default => 'invalid',
        };
    }

    /**
     * Emit a heartbeat comment to keep the SSE connection alive.
     */
    private function emitHeartbeat(): void
    {
        echo sprintf(': %s', self::HEARTBEAT_COMMENT) . "\n\n";
        $this->flushOutputBuffers();
    }

    /**
     * Emit a payload to the SSE stream.
     *
     * @param  array<string, mixed>  $payload
     */
    private function emitPayload(array $payload): void
    {
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $json = json_encode(['error' => 'Unable to encode payload.']);
        }

        if ($json === false) {
            return;
        }

        echo "event: totals\n";
        echo sprintf('data: %s', $json) . "\n\n";
        $this->flushOutputBuffers();
    }

    /**
     * Flush the output buffers to the client.
     */
    private function flushOutputBuffers(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
    }

    /**
     * Fetch the latest attendance update timestamp for the event.
     */
    private function latestAttendanceChange(Event $event): ?CarbonImmutable
    {
        $timestamp = Attendance::query()
            ->where('event_id', $event->id)
            ->max('updated_at');

        if ($timestamp === null) {
            return null;
        }

        return CarbonImmutable::parse($timestamp);
    }

    /**
     * Ensure hostess users maintain an assignment for the event.
     */
    private function assertCanAccessEvent(User $authUser, Event $event): void
    {
        if ($this->isSuperAdmin($authUser)) {
            return;
        }

        $authUser->loadMissing('roles');
        $isHostess = $authUser->roles->contains(fn ($role): bool => $role->code === 'hostess');

        if (! $isHostess) {
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
     * Locate the event ensuring tenant access constraints.
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
}
