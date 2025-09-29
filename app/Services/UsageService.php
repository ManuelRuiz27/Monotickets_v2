<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\UsageCounter;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class UsageService
{
    public function __construct(
        private readonly DatabaseManager $database
    ) {
    }

    /**
     * Increment the usage counter for the provided key.
     *
     * @param array<string, mixed> $context
     */
    public function increment(Tenant $tenant, string $key, array $context = [], int $amount = 1): UsageCounter
    {
        [$periodStart, $periodEnd] = $this->resolvePeriod($context);
        $eventId = $this->extractEventId($key, $context);

        return $this->database->transaction(function () use ($tenant, $key, $eventId, $amount, $periodStart, $periodEnd) {
            $counterQuery = $this->buildCounterQuery($tenant, $key, $eventId, $periodStart, $periodEnd)->lockForUpdate();

            /** @var UsageCounter|null $counter */
            $counter = $counterQuery->first();

            if ($counter === null) {
                $counter = new UsageCounter([
                    'tenant_id' => $tenant->id,
                    'key' => $key,
                    'event_id' => $eventId,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                ]);
                $counter->value = 0;
            }

            $newValue = max(0, $counter->value + $amount);
            $counter->value = $newValue;
            $counter->updated_at = CarbonImmutable::now();
            $counter->save();

            return $counter;
        });
    }

    /**
     * Retrieve the counter value for the current billing period.
     *
     * @param array<string, mixed> $context
     */
    public function currentValue(Tenant $tenant, string $key, array $context = []): int
    {
        $counter = $this->currentCounter($tenant, $key, $context);

        return $counter?->value ?? 0;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function currentCounter(Tenant $tenant, string $key, array $context = []): ?UsageCounter
    {
        [$periodStart, $periodEnd] = $this->resolvePeriod($context);
        $eventId = $this->extractEventId($key, $context, allowNull: true);

        /** @var UsageCounter|null $counter */
        $counter = $this->buildCounterQuery($tenant, $key, $eventId, $periodStart, $periodEnd)->first();

        return $counter;
    }

    private function buildCounterQuery(
        Tenant $tenant,
        string $key,
        ?string $eventId,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd
    ): Builder {
        $query = UsageCounter::query()
            ->where('tenant_id', $tenant->id)
            ->where('key', $key)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd);

        if ($eventId === null) {
            $query->whereNull('event_id');
        } else {
            $query->where('event_id', $eventId);
        }

        return $query;
    }

    /**
     * Determine the period boundaries for the counter.
     *
     * @param array<string, mixed> $context
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolvePeriod(array $context): array
    {
        $reference = Arr::get($context, 'date');

        if ($reference instanceof CarbonImmutable) {
            $now = $reference;
        } elseif ($reference instanceof \DateTimeInterface) {
            $now = CarbonImmutable::instance($reference);
        } else {
            $now = CarbonImmutable::now();
        }

        $start = $now->startOfMonth();
        $end = $now->endOfMonth();

        return [$start, $end];
    }

    /**
     * Extract the event identifier for scan counters.
     *
     * @param array<string, mixed> $context
     */
    private function extractEventId(string $key, array $context, bool $allowNull = false): ?string
    {
        if ($key !== UsageCounter::KEY_SCAN_COUNT) {
            return null;
        }

        $eventId = Arr::get($context, 'event_id');

        if ($eventId === null && ! $allowNull) {
            throw new InvalidArgumentException('An event_id is required when recording scan usage.');
        }

        if ($eventId !== null && ! is_string($eventId)) {
            throw new InvalidArgumentException('The event_id must be a string.');
        }

        return $eventId;
    }

}
