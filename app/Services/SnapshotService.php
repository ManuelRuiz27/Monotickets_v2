<?php

namespace App\Services;

use App\Models\Event;
use App\Models\ReportSnapshot;
use App\Services\Analytics\AnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Handle caching of analytics calculations for dashboards.
 */
class SnapshotService
{
    public function __construct(private readonly AnalyticsService $analytics)
    {
    }

    /**
     * Compute a report snapshot or reuse a cached version when still valid.
     *
     * @param  array<string, mixed>  $params
     * @return array<mixed>
     */
    public function compute(string $type, array $params): array
    {
        $eventId = (string) Arr::get($params, 'event_id');

        if ($eventId === '') {
            throw new InvalidArgumentException('An event_id parameter is required to compute a snapshot.');
        }

        $tenantId = Arr::get($params, 'tenant_id');

        if ($tenantId === null) {
            $tenantId = Event::query()->whereKey($eventId)->value('tenant_id');
        }

        if ($tenantId === null) {
            throw new InvalidArgumentException('Unable to resolve the tenant for the provided event.');
        }

        $ttlSeconds = Arr::get($params, 'ttl');
        $ttlSeconds = is_numeric($ttlSeconds) ? max(0, (int) $ttlSeconds) : null;

        $normalisedParams = $this->normaliseParams(Arr::except($params, ['tenant_id', 'event_id', 'ttl']));

        $startedAt = microtime(true);

        /** @var ReportSnapshot|null $existing */
        $existing = ReportSnapshot::query()
            ->where('tenant_id', $tenantId)
            ->where('event_id', $eventId)
            ->where('type', $type)
            ->where('params_json', $normalisedParams)
            ->first();

        if ($existing !== null && ! $existing->hasExpired()) {
            $this->logSnapshotTiming($tenantId, $eventId, $type, $startedAt, true);
            Log::info('metrics.counter', [
                'metric' => 'snapshot_cache_hit',
                'tenant_id' => $tenantId,
                'event_id' => $eventId,
                'snapshot_type' => $type,
            ]);

            return $existing->result_json ?? [];
        }

        $result = $this->computeFreshResult($type, $eventId, $params);

        $this->logSnapshotTiming($tenantId, $eventId, $type, $startedAt, false);

        DB::transaction(function () use (&$existing, $tenantId, $eventId, $type, $normalisedParams, $ttlSeconds, $result): void {
            $payload = [
                'tenant_id' => $tenantId,
                'event_id' => $eventId,
                'type' => $type,
                'params_json' => $normalisedParams,
                'result_json' => $result,
                'computed_at' => CarbonImmutable::now(),
                'ttl_seconds' => $ttlSeconds,
            ];

            if ($existing === null) {
                $existing = ReportSnapshot::query()->create($payload);

                return;
            }

            $existing->fill($payload);
            $existing->save();
        });

        return $result;
    }

    private function logSnapshotTiming(
        string $tenantId,
        string $eventId,
        string $type,
        float $startedAt,
        bool $cacheHit
    ): void {
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        Log::info('metrics.distribution', [
            'metric' => 'snapshot_compute_time_ms',
            'tenant_id' => $tenantId,
            'event_id' => $eventId,
            'snapshot_type' => $type,
            'value' => $durationMs,
            'cache_hit' => $cacheHit,
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function normaliseParams(array $params): array
    {
        ksort($params);

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $params[$key] = $this->normaliseParams($value);

                continue;
            }

            if ($value instanceof CarbonImmutable) {
                $params[$key] = $value->toISOString();

                continue;
            }

            if ($value instanceof \DateTimeInterface) {
                $params[$key] = CarbonImmutable::make($value)?->toISOString();

                continue;
            }

            if (is_string($value) && strtotime($value) !== false) {
                $params[$key] = CarbonImmutable::make($value)?->toISOString();
            }
        }

        return $params;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<mixed>
     */
    private function computeFreshResult(string $type, string $eventId, array $params): array
    {
        $from = Arr::get($params, 'from');
        $to = Arr::get($params, 'to');

        return match ($type) {
            'overview' => $this->analytics->overview($eventId, $from, $to),
            'attendance_by_hour' => $this->analytics->attendanceByHour($eventId, $from, $to),
            'rsvp_funnel' => $this->analytics->rsvpFunnel($eventId),
            'checkpoint_totals' => $this->analytics->checkpointTotals($eventId, $from, $to),
            'guests_by_list' => $this->analytics->guestsByList($eventId),
            default => throw new InvalidArgumentException(sprintf('Snapshot type "%s" is not supported.', $type)),
        };
    }
}
