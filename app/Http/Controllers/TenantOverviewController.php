<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithTenants;
use App\Models\Event;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\UsageCounter;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class TenantOverviewController extends Controller
{
    use InteractsWithTenants;

    private const TOP_SCAN_EVENTS_LIMIT = 5;

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->resolveTenantContext($request, $user);

        if ($tenantId === null) {
            $this->throwValidationException([
                'tenant_id' => ['Tenant context is required to retrieve subscription data.'],
            ]);
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->with(['latestSubscription.plan'])->find($tenantId);

        if ($tenant === null) {
            return ApiResponse::error('tenant_not_found', 'The selected tenant could not be found.', null, Response::HTTP_NOT_FOUND);
        }

        /** @var Subscription|null $subscription */
        $subscription = $tenant->latestSubscription;
        $plan = $subscription?->plan;

        $periodStart = $this->resolvePeriodBoundary($subscription?->current_period_start, CarbonImmutable::now()->startOfMonth());
        $periodEnd = $this->resolvePeriodBoundary($subscription?->current_period_end, CarbonImmutable::now()->endOfMonth());

        $usage = $this->loadUsageTotals($tenant->id, $periodStart, $periodEnd);
        $scanBreakdown = $this->loadScanBreakdown($tenant->id, $periodStart, $periodEnd);

        $effectiveLimits = $tenant->effectiveLimits($plan);

        $latestInvoice = $this->loadLatestInvoice($tenant->id);

        return response()->json([
            'data' => [
                'tenant' => [
                    'id' => (string) $tenant->id,
                    'name' => $tenant->name,
                ],
                'plan' => $plan !== null ? [
                    'id' => (string) $plan->id,
                    'code' => $plan->code,
                    'name' => $plan->name,
                    'billing_cycle' => $plan->billing_cycle,
                    'price_cents' => (int) $plan->price_cents,
                    'limits' => $plan->limits_json ?? [],
                ] : null,
                'effective_limits' => $effectiveLimits,
                'subscription' => $subscription !== null ? [
                    'id' => (string) $subscription->id,
                    'status' => $subscription->status,
                    'current_period_start' => optional($subscription->current_period_start)->toISOString(),
                    'current_period_end' => optional($subscription->current_period_end)->toISOString(),
                    'trial_end' => optional($subscription->trial_end)->toISOString(),
                    'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
                ] : null,
                'usage' => $usage,
                'scan_breakdown' => $scanBreakdown,
                'latest_invoice' => $latestInvoice,
                'period' => [
                    'start' => $periodStart->toIso8601String(),
                    'end' => $periodEnd->toIso8601String(),
                ],
            ],
        ]);
    }

    private function resolvePeriodBoundary(mixed $value, CarbonImmutable $fallback): CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value !== null) {
            return CarbonImmutable::parse((string) $value);
        }

        return $fallback;
    }

    /**
     * @return array{event_count:int,user_count:int,scan_count:int}
     */
    private function loadUsageTotals(string $tenantId, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $counters = UsageCounter::query()
            ->select(['key'])
            ->selectRaw('SUM(value) as total_value')
            ->where('tenant_id', $tenantId)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->groupBy('key')
            ->get()
            ->reduce(function (array $carry, UsageCounter $counter): array {
                $carry[$counter->key] = (int) $counter->total_value;

                return $carry;
            }, []);

        return [
            'event_count' => (int) ($counters[UsageCounter::KEY_EVENT_COUNT] ?? 0),
            'user_count' => (int) ($counters[UsageCounter::KEY_USER_COUNT] ?? 0),
            'scan_count' => (int) ($counters[UsageCounter::KEY_SCAN_COUNT] ?? 0),
        ];
    }

    /**
     * @return array<int, array{event_id:string,event_name:?string,value:int}>
     */
    private function loadScanBreakdown(string $tenantId, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $entries = UsageCounter::query()
            ->select(['event_id'])
            ->selectRaw('SUM(value) as total_value')
            ->where('tenant_id', $tenantId)
            ->where('key', UsageCounter::KEY_SCAN_COUNT)
            ->whereNotNull('event_id')
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->groupBy('event_id')
            ->orderByDesc('total_value')
            ->limit(self::TOP_SCAN_EVENTS_LIMIT)
            ->get();

        $eventNames = Event::query()
            ->whereIn('id', $entries->pluck('event_id')->filter()->all())
            ->pluck('name', 'id');

        return $entries->map(static function (UsageCounter $counter) use ($eventNames): array {
            $eventId = (string) $counter->event_id;

            return [
                'event_id' => $eventId,
                'event_name' => $eventNames[$eventId] ?? null,
                'value' => (int) $counter->total_value,
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadLatestInvoice(string $tenantId): ?array
    {
        /** @var Invoice|null $invoice */
        $invoice = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('issued_at')
            ->orderByDesc('created_at')
            ->first();

        if ($invoice === null) {
            return null;
        }

        $invoice->loadMissing('payments');

        return [
            'id' => (string) $invoice->id,
            'status' => $invoice->status,
            'period_start' => optional($invoice->period_start)->toIso8601String(),
            'period_end' => optional($invoice->period_end)->toIso8601String(),
            'issued_at' => optional($invoice->issued_at)->toIso8601String(),
            'due_at' => optional($invoice->due_at)->toIso8601String(),
            'paid_at' => optional($invoice->paid_at)->toIso8601String(),
            'subtotal_cents' => (int) $invoice->subtotal_cents,
            'tax_cents' => (int) $invoice->tax_cents,
            'total_cents' => (int) $invoice->total_cents,
            'line_items' => Collection::make($invoice->line_items_json ?? [])->map(static function (array $item): array {
                return [
                    'type' => $item['type'] ?? 'custom',
                    'description' => $item['description'] ?? '',
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'unit_price_cents' => (int) ($item['unit_price_cents'] ?? 0),
                    'amount_cents' => (int) ($item['amount_cents'] ?? 0),
                ];
            })->values()->all(),
        ];
    }
}
