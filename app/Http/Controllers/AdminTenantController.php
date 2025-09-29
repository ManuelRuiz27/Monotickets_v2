<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\Tenant\TenantStoreRequest;
use App\Http\Requests\Admin\Tenant\TenantUpdateRequest;
use App\Models\Event;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\UsageCounter;
use App\Support\ApiResponse;
use App\Support\Audit\RecordsAuditLogs;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Administrative operations for managing tenants and their subscriptions.
 */
class AdminTenantController extends Controller
{
    use RecordsAuditLogs;

    /**
     * Display a paginated list of tenants with subscription and usage details.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $this->validate($request, [
            'status' => ['nullable', 'string', 'in:trialing,active,paused,canceled,none'],
            'search' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $validated['status'] ?? null;
        $search = $validated['search'] ?? null;
        $perPage = (int) ($validated['per_page'] ?? 15);

        $query = Tenant::query()
            ->with(['latestSubscription.plan'])
            ->orderBy('name');

        if ($status === 'none') {
            $query->whereDoesntHave('latestSubscription');
        } elseif ($status !== null) {
            $query->whereHas('latestSubscription', static function ($subscriptionQuery) use ($status): void {
                $subscriptionQuery->where('status', $status);
            });
        }

        if ($search !== null && $search !== '') {
            $query->where(function ($tenantQuery) use ($search): void {
                $tenantQuery
                    ->where('name', 'like', '%' . $search . '%')
                    ->orWhere('slug', 'like', '%' . $search . '%');
            });
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage);

        $usageByTenant = $this->loadCurrentUsage($paginator->pluck('id')->all());

        $data = $paginator->getCollection()->map(function (Tenant $tenant) use ($usageByTenant): array {
            return $this->formatTenantSummary($tenant, $usageByTenant[$tenant->id] ?? []);
        });

        return ApiResponse::paginate($data->all(), [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'total_pages' => $paginator->lastPage(),
        ]);
    }

    /**
     * Create a new tenant and bootstrap its subscription.
     */
    public function store(TenantStoreRequest $request): JsonResponse
    {
        /** @var \App\Models\User $authUser */
        $authUser = $request->user();
        $validated = $request->validated();

        /** @var Plan $plan */
        $plan = Plan::query()->findOrFail($validated['plan_id']);

        $trialDays = (int) ($validated['trial_days'] ?? 0);
        $now = CarbonImmutable::now();
        $trialEnd = $trialDays > 0 ? $now->addDays($trialDays) : null;
        $subscriptionStatus = $trialEnd !== null ? Subscription::STATUS_TRIALING : Subscription::STATUS_ACTIVE;
        $periodEnd = $plan->billing_cycle === 'yearly' ? $now->addYear() : $now->addMonth();

        $limitOverrides = $this->normalizeOverrides($validated['limit_overrides'] ?? null);
        $settings = $limitOverrides !== [] ? ['limits_override' => $limitOverrides] : [];

        /** @var Tenant $tenant */
        $tenant = DB::transaction(function () use ($validated, $plan, $settings, $now, $periodEnd, $subscriptionStatus, $trialEnd) {
            $tenant = Tenant::create([
                'name' => $validated['name'],
                'slug' => $validated['slug'],
                'status' => $validated['status'] ?? 'active',
                'plan' => $plan->code,
                'settings_json' => $settings !== [] ? $settings : null,
            ]);

            $subscription = new Subscription([
                'status' => $subscriptionStatus,
                'current_period_start' => $now,
                'current_period_end' => $periodEnd,
                'cancel_at_period_end' => false,
                'trial_end' => $trialEnd,
                'meta_json' => [],
            ]);

            $subscription->tenant()->associate($tenant);
            $subscription->plan()->associate($plan);
            $subscription->save();

            $tenant->setRelation('latestSubscription', $subscription);

            return $tenant;
        });

        $tenant->load('latestSubscription.plan');

        $this->recordAuditLog($authUser, $request, 'tenant', $tenant->id, 'created', [
            'after' => $this->snapshotForAudit($tenant, $tenant->latestSubscription),
        ], $tenant->id);

        return response()->json([
            'data' => $this->formatTenantSummary($tenant, $this->loadCurrentUsage([$tenant->id])[$tenant->id] ?? []),
        ], 201);
    }

    /**
     * Update tenant subscription, plan or limit overrides.
     */
    public function update(TenantUpdateRequest $request, string $tenant): JsonResponse
    {
        /** @var Tenant|null $tenantModel */
        $tenantModel = Tenant::query()->with(['latestSubscription.plan'])->find($tenant);

        if ($tenantModel === null) {
            throw ValidationException::withMessages([
                'tenant' => ['The selected tenant could not be found.'],
            ]);
        }

        /** @var \App\Models\User $authUser */
        $authUser = $request->user();
        $validated = $request->validated();

        $beforeAudit = $this->snapshotForAudit($tenantModel, $tenantModel->latestSubscription);
        $beforeDiff = $this->snapshotForDiff($tenantModel, $tenantModel->latestSubscription);

        $plan = null;
        if (array_key_exists('plan_id', $validated)) {
            $plan = Plan::query()->findOrFail($validated['plan_id']);
        }

        DB::transaction(function () use (&$tenantModel, $validated, $plan): void {
            /** @var Subscription|null $subscription */
            $subscription = $tenantModel->latestSubscription;

            if (array_key_exists('name', $validated)) {
                $tenantModel->name = $validated['name'];
            }

            if (array_key_exists('slug', $validated)) {
                $tenantModel->slug = $validated['slug'];
            }

            if (array_key_exists('status', $validated)) {
                $tenantModel->status = $validated['status'];
            }

            if ($plan !== null) {
                $tenantModel->plan = $plan->code;

                if ($subscription === null || $subscription->status === Subscription::STATUS_CANCELED) {
                    $now = CarbonImmutable::now();
                    $subscription = new Subscription([
                        'status' => $validated['subscription_status'] ?? Subscription::STATUS_ACTIVE,
                        'current_period_start' => $now,
                        'current_period_end' => $plan->billing_cycle === 'yearly'
                            ? $now->addYear()
                            : $now->addMonth(),
                        'cancel_at_period_end' => (bool) ($validated['cancel_at_period_end'] ?? false),
                        'trial_end' => isset($validated['trial_end']) && $validated['trial_end'] !== null
                            ? CarbonImmutable::parse($validated['trial_end'])
                            : null,
                        'meta_json' => [],
                    ]);

                    $subscription->tenant()->associate($tenantModel);
                    $subscription->plan()->associate($plan);
                    $subscription->save();
                } else {
                    $subscription->plan()->associate($plan);
                }
            }

            if ($subscription === null) {
                if (array_key_exists('subscription_status', $validated)
                    || array_key_exists('cancel_at_period_end', $validated)
                    || array_key_exists('trial_end', $validated)) {
                    throw ValidationException::withMessages([
                        'subscription' => ['The tenant does not have an active subscription to update.'],
                    ]);
                }
            } else {
                if (array_key_exists('subscription_status', $validated)) {
                    $subscription->status = $validated['subscription_status'];
                }

                if (array_key_exists('cancel_at_period_end', $validated)) {
                    $subscription->cancel_at_period_end = (bool) $validated['cancel_at_period_end'];
                }

                if (array_key_exists('trial_end', $validated)) {
                    $subscription->trial_end = $validated['trial_end'] !== null
                        ? CarbonImmutable::parse($validated['trial_end'])
                        : null;
                }

                if ($subscription->isDirty()) {
                    $subscription->save();
                }

                $tenantModel->setRelation('latestSubscription', $subscription);
            }

            if (array_key_exists('limit_overrides', $validated)) {
                $this->applyLimitOverrides($tenantModel, $validated['limit_overrides']);
            }

            if ($tenantModel->isDirty()) {
                $tenantModel->save();
            }
        });

        $tenantModel = $tenantModel->fresh(['latestSubscription.plan']);

        $afterAudit = $this->snapshotForAudit($tenantModel, $tenantModel->latestSubscription);
        $afterDiff = $this->snapshotForDiff($tenantModel, $tenantModel->latestSubscription);

        $changes = $this->calculateDifferences($beforeDiff, $afterDiff);

        if ($changes !== []) {
            $this->recordAuditLog($authUser, $request, 'tenant', $tenantModel->id, 'updated', [
                'before' => $beforeAudit,
                'after' => $afterAudit,
                'changes' => $changes,
            ], $tenantModel->id);
        }

        return response()->json([
            'data' => $this->formatTenantSummary($tenantModel, $this->loadCurrentUsage([$tenantModel->id])[$tenantModel->id] ?? []),
        ]);
    }

    /**
     * Retrieve usage counters for the tenant as a time series.
     */
    public function usage(Request $request, string $tenant): JsonResponse
    {
        /** @var Tenant|null $tenantModel */
        $tenantModel = Tenant::query()->find($tenant);

        if ($tenantModel === null) {
            throw ValidationException::withMessages([
                'tenant' => ['The selected tenant could not be found.'],
            ]);
        }

        $validated = $this->validate($request, [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $now = CarbonImmutable::now();
        $from = isset($validated['from'])
            ? CarbonImmutable::parse($validated['from'])->startOfMonth()
            : $now->subMonths(5)->startOfMonth();
        $to = isset($validated['to'])
            ? CarbonImmutable::parse($validated['to'])->endOfMonth()
            : $now->endOfMonth();

        $series = $this->buildUsageSeries($tenantModel, $from, $to);

        return response()->json([
            'data' => $series,
        ]);
    }

    /**
     * @param array<int, string> $tenantIds
     * @return array<string, array<string, int>>
     */
    private function loadCurrentUsage(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        $now = CarbonImmutable::now();
        $periodStart = $now->startOfMonth();
        $periodEnd = $now->endOfMonth();

        return UsageCounter::query()
            ->select(['tenant_id', 'key'])
            ->selectRaw('SUM(value) as total_value')
            ->whereIn('tenant_id', $tenantIds)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->groupBy('tenant_id', 'key')
            ->get()
            ->reduce(function (array $carry, UsageCounter $counter): array {
                $carry[$counter->tenant_id][$counter->key] = (int) $counter->total_value;

                return $carry;
            }, []);
    }

    /**
     * @param array<string, int> $usage
     * @return array<string, mixed>
     */
    private function formatTenantSummary(Tenant $tenant, array $usage): array
    {
        /** @var Subscription|null $subscription */
        $subscription = $tenant->latestSubscription;
        $plan = $subscription?->plan;

        return [
            'id' => (string) $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status,
            'plan' => $plan !== null ? [
                'id' => (string) $plan->id,
                'code' => $plan->code,
                'name' => $plan->name,
                'billing_cycle' => $plan->billing_cycle,
            ] : null,
            'subscription' => $subscription !== null ? [
                'id' => (string) $subscription->id,
                'status' => $subscription->status,
                'current_period_start' => optional($subscription->current_period_start)->toISOString(),
                'current_period_end' => optional($subscription->current_period_end)->toISOString(),
                'trial_end' => optional($subscription->trial_end)->toISOString(),
                'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
            ] : null,
            'usage' => [
                'event_count' => (int) ($usage[UsageCounter::KEY_EVENT_COUNT] ?? 0),
                'user_count' => (int) ($usage[UsageCounter::KEY_USER_COUNT] ?? 0),
                'scan_count' => (int) ($usage[UsageCounter::KEY_SCAN_COUNT] ?? 0),
            ],
            'limits_override' => $tenant->limitOverrides(),
            'created_at' => optional($tenant->created_at)->toISOString(),
            'updated_at' => optional($tenant->updated_at)->toISOString(),
        ];
    }

    /**
     * Build a usage series with breakdown per period.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildUsageSeries(Tenant $tenant, CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var EloquentCollection<int, UsageCounter> $counters */
        $counters = UsageCounter::query()
            ->where('tenant_id', $tenant->id)
            ->where('period_start', '>=', $from)
            ->where('period_end', '<=', $to)
            ->orderBy('period_start')
            ->get();

        $eventIds = $counters
            ->where('key', UsageCounter::KEY_SCAN_COUNT)
            ->pluck('event_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $events = $eventIds !== []
            ? Event::query()->whereIn('id', $eventIds)->pluck('name', 'id')
            : collect();

        $series = [];

        foreach ($counters as $counter) {
            $periodStart = $counter->period_start instanceof CarbonImmutable
                ? $counter->period_start
                : CarbonImmutable::parse((string) $counter->period_start);
            $periodEnd = $counter->period_end instanceof CarbonImmutable
                ? $counter->period_end
                : CarbonImmutable::parse((string) $counter->period_end);

            $periodKey = $periodStart->toIso8601String();

            if (! array_key_exists($periodKey, $series)) {
                $series[$periodKey] = [
                    'period_start' => $periodStart->toIso8601String(),
                    'period_end' => $periodEnd->toIso8601String(),
                    'event_count' => 0,
                    'user_count' => 0,
                    'scan_total' => 0,
                    'scan_breakdown' => [],
                ];
            }

            if ($counter->key === UsageCounter::KEY_EVENT_COUNT) {
                $series[$periodKey]['event_count'] += (int) $counter->value;
            } elseif ($counter->key === UsageCounter::KEY_USER_COUNT) {
                $series[$periodKey]['user_count'] += (int) $counter->value;
            } elseif ($counter->key === UsageCounter::KEY_SCAN_COUNT) {
                $series[$periodKey]['scan_total'] += (int) $counter->value;

                if ($counter->event_id !== null) {
                    $eventId = (string) $counter->event_id;
                    $series[$periodKey]['scan_breakdown'][$eventId] ??= [
                        'event_id' => $eventId,
                        'event_name' => $events[$eventId] ?? null,
                        'value' => 0,
                    ];
                    $series[$periodKey]['scan_breakdown'][$eventId]['value'] += (int) $counter->value;
                }
            }
        }

        return collect($series)
            ->sortKeys()
            ->map(static function (array $entry): array {
                $entry['scan_breakdown'] = array_values($entry['scan_breakdown']);

                return $entry;
            })
            ->values()
            ->all();
    }

    /**
     * Normalize overrides ensuring integer casting.
     *
     * @param array<string, mixed>|null $overrides
     * @return array<string, int|null>
     */
    private function normalizeOverrides(?array $overrides): array
    {
        if ($overrides === null) {
            return [];
        }

        $normalized = [];

        foreach ($overrides as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if ($value === null) {
                $normalized[$key] = null;
                continue;
            }

            if (is_numeric($value)) {
                $normalized[$key] = (int) $value;
            }
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * Apply limit overrides to the tenant settings.
     */
    private function applyLimitOverrides(Tenant $tenant, ?array $overrides): void
    {
        $settings = $tenant->settings_json;

        if (! is_array($settings)) {
            $settings = [];
        }

        if ($overrides === null) {
            unset($settings['limits_override']);
        } else {
            $normalized = $this->normalizeOverrides($overrides);

            if ($normalized === []) {
                unset($settings['limits_override']);
            } else {
                $settings['limits_override'] = $normalized;
            }
        }

        $tenant->settings_json = $settings === [] ? null : $settings;
    }

    /**
     * Build a snapshot suitable for audit logging payloads.
     *
     * @return array<string, mixed>
     */
    private function snapshotForAudit(Tenant $tenant, ?Subscription $subscription): array
    {
        return [
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status,
            'plan' => $tenant->plan,
            'subscription_plan_id' => $subscription?->plan_id,
            'subscription_status' => $subscription?->status,
            'cancel_at_period_end' => $subscription?->cancel_at_period_end,
            'trial_end' => optional($subscription?->trial_end)->toISOString(),
            'limits_override' => $tenant->limitOverrides(),
        ];
    }

    /**
     * Build a flattened snapshot for diffing purposes.
     *
     * @return array<string, mixed>
     */
    private function snapshotForDiff(Tenant $tenant, ?Subscription $subscription): array
    {
        return [
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status,
            'plan' => $tenant->plan,
            'subscription_plan_id' => $subscription?->plan_id,
            'subscription_status' => $subscription?->status,
            'cancel_at_period_end' => $subscription?->cancel_at_period_end,
            'trial_end' => optional($subscription?->trial_end)->toISOString(),
            'limits_override' => json_encode($tenant->limitOverrides()),
        ];
    }
}
