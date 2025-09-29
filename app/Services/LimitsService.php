<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\UsageCounter;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class LimitsService
{
    public const ACTION_CREATE_EVENT = 'event.create';
    public const ACTION_ACTIVATE_EVENT = 'event.activate';
    public const ACTION_CREATE_USER = 'user.create';
    public const ACTION_ACTIVATE_USER = 'user.activate';
    public const ACTION_RECORD_SCAN = 'scan.record';

    public function __construct(
        private readonly UsageService $usageService
    ) {
    }

    /**
     * Assert that the tenant can perform the action without exceeding limits.
     *
     * @param array<string, mixed> $context
     */
    public function assertCan(Tenant $tenant, string $action, array $context = []): void
    {
        $plan = $this->resolvePlan($tenant);

        if ($plan === null) {
            return;
        }

        $limits = $plan->limits_json ?? [];

        switch ($action) {
            case self::ACTION_CREATE_EVENT:
            case self::ACTION_ACTIVATE_EVENT:
                $this->assertWithinLimit(
                    $limits,
                    'max_events',
                    fn (): int => $this->usageService->currentValue($tenant, UsageCounter::KEY_EVENT_COUNT),
                    'The tenant has reached the maximum number of active events allowed by the subscription.'
                );
                break;

            case self::ACTION_CREATE_USER:
            case self::ACTION_ACTIVATE_USER:
                $this->assertWithinLimit(
                    $limits,
                    'max_users',
                    fn (): int => $this->usageService->currentValue($tenant, UsageCounter::KEY_USER_COUNT),
                    'The tenant has reached the maximum number of active users allowed by the subscription.'
                );
                break;

            case self::ACTION_RECORD_SCAN:
                $eventId = Arr::get($context, 'event_id');

                if (! is_string($eventId)) {
                    throw new InvalidArgumentException('An event_id context value is required to record scans.');
                }

                $this->assertWithinLimit(
                    $limits,
                    'max_scans_per_event',
                    fn () use ($tenant, $eventId): int => $this->usageService->currentValue(
                        $tenant,
                        UsageCounter::KEY_SCAN_COUNT,
                        ['event_id' => $eventId]
                    ),
                    'The tenant has reached the maximum number of scans for this event allowed by the subscription.'
                );
                break;
        }
    }

    /**
     * @param array<string, mixed> $limits
     * @param callable(): int $usageResolver
     */
    private function assertWithinLimit(array $limits, string $limitKey, callable $usageResolver, string $message): void
    {
        if (! array_key_exists($limitKey, $limits) || $limits[$limitKey] === null) {
            return;
        }

        $limit = (int) $limits[$limitKey];

        if ($limit <= 0) {
            throw ValidationException::withMessages([
                'limit' => [$message],
            ]);
        }

        $usage = $usageResolver();

        if ($usage >= $limit) {
            throw ValidationException::withMessages([
                'limit' => [$message],
            ]);
        }
    }

    private function resolvePlan(Tenant $tenant): ?Plan
    {
        $subscription = $tenant->activeSubscription();

        if ($subscription === null) {
            return null;
        }

        return $subscription->plan;
    }
}
