<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Services\Billing\Exceptions\BillingPeriodAlreadyClosedException;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BillingService
{
    public function __construct(private readonly DatabaseManager $database)
    {
    }

    /**
     * Generate a preview for the upcoming invoice.
     *
     * @return array<string, mixed>
     */
    public function preview(Subscription $subscription, CarbonImmutable $closeAt, array $simulation = []): array
    {
        $periodStart = $subscription->current_period_start instanceof CarbonImmutable
            ? $subscription->current_period_start
            : CarbonImmutable::parse((string) $subscription->current_period_start);
        $periodEnd = $subscription->current_period_end instanceof CarbonImmutable
            ? $subscription->current_period_end
            : CarbonImmutable::parse((string) $subscription->current_period_end);

        $closeAt = $this->clampDate($closeAt, $periodStart, $periodEnd->addSecond());

        return $this->buildInvoiceData($subscription, $periodStart, $periodEnd, $closeAt, $simulation);
    }

    /**
     * Close the current billing period and create a pending invoice.
     */
    public function closePeriod(Subscription $subscription): Invoice
    {
        $periodStart = $subscription->current_period_start instanceof CarbonImmutable
            ? $subscription->current_period_start
            : CarbonImmutable::parse((string) $subscription->current_period_start);
        $periodEnd = $subscription->current_period_end instanceof CarbonImmutable
            ? $subscription->current_period_end
            : CarbonImmutable::parse((string) $subscription->current_period_end);

        $existingInvoice = Invoice::query()
            ->where('tenant_id', $subscription->tenant_id)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->first();

        if ($existingInvoice !== null) {
            throw new BillingPeriodAlreadyClosedException($existingInvoice);
        }

        $invoiceData = $this->buildInvoiceData($subscription, $periodStart, $periodEnd);

        /** @var Invoice $invoice */
        $invoice = $this->database->transaction(function () use ($subscription, $periodStart, $periodEnd, $invoiceData) {
            $invoice = new Invoice([
                'tenant_id' => $subscription->tenant_id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'subtotal_cents' => $invoiceData['subtotal_cents'],
                'tax_cents' => $invoiceData['tax_cents'],
                'total_cents' => $invoiceData['total_cents'],
                'status' => Invoice::STATUS_PENDING,
                'issued_at' => CarbonImmutable::now(),
                'due_at' => CarbonImmutable::now()->addDays(15),
                'line_items_json' => $invoiceData['line_items'],
            ]);

            $invoice->save();

            return $invoice;
        });

        return $invoice;
    }

    /**
     * Build the invoice data for the provided subscription and period.
     *
     * @return array{
     *     period_start: CarbonImmutable,
     *     period_end: CarbonImmutable,
     *     subtotal_cents: int,
     *     tax_cents: int,
     *     total_cents: int,
     *     line_items: array<int, array<string, mixed>>,
     * }
     */
    private function buildInvoiceData(Subscription $subscription, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, ?CarbonImmutable $prorateUntil = null, array $usageOverrides = []): array
    {
        $plan = $subscription->plan;
        $limits = $plan->limits_json ?? [];

        $lineItems = Collection::make();

        $baseDescription = sprintf('Licencia %s (%s)', $plan->name, Str::upper($plan->billing_cycle));
        $planAmount = (int) $plan->price_cents;
        $proratedPlanAmount = $this->calculateProratedAmount($planAmount, $periodStart, $periodEnd, $prorateUntil);

        $lineItems->push([
            'type' => 'base_plan',
            'description' => $baseDescription,
            'quantity' => 1,
            'unit_price_cents' => $proratedPlanAmount,
            'amount_cents' => $proratedPlanAmount,
        ]);

        $usage = $this->resolveUsage($subscription, $periodStart, $periodEnd, $usageOverrides);

        $includedUsers = (int) ($limits['included_users'] ?? 0);
        $userOveragePrice = (int) ($limits['user_overage_price_cents'] ?? 0);
        $userOverage = max(0, $usage['user_count'] - $includedUsers);

        if ($userOverage > 0 && $userOveragePrice > 0) {
            $lineItems->push([
                'type' => 'user_overage',
                'description' => 'Excedentes de usuarios',
                'quantity' => $userOverage,
                'unit_price_cents' => $userOveragePrice,
                'amount_cents' => $userOverage * $userOveragePrice,
            ]);
        }

        $includedScans = (int) ($limits['included_scans'] ?? 0);
        $scanOveragePrice = (int) ($limits['scan_overage_price_cents'] ?? 0);
        $scanOverage = max(0, $usage['scan_count'] - $includedScans);

        if ($scanOverage > 0 && $scanOveragePrice > 0) {
            $lineItems->push([
                'type' => 'scan_overage',
                'description' => 'Excedentes de escaneos',
                'quantity' => $scanOverage,
                'unit_price_cents' => $scanOveragePrice,
                'amount_cents' => $scanOverage * $scanOveragePrice,
            ]);
        }

        $subtotal = $lineItems->sum(fn (array $item) => (int) $item['amount_cents']);
        $tax = $this->calculateTax($subtotal, $limits);
        $total = $subtotal + $tax;

        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'subtotal_cents' => $subtotal,
            'tax_cents' => $tax,
            'total_cents' => $total,
            'line_items' => $lineItems->values()->all(),
        ];
    }

    /**
     * Retrieve usage counters for the billing period.
     *
     * @return array{user_count:int,scan_count:int}
     */
    private function resolveUsage(Subscription $subscription, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, array $usageOverrides = []): array
    {
        $userOverride = $usageOverrides['user_count'] ?? null;
        $scanOverride = $usageOverrides['scan_count'] ?? null;

        $userUsage = $userOverride !== null
            ? (int) $userOverride
            : UsageCounter::query()
                ->where('tenant_id', $subscription->tenant_id)
                ->where('key', UsageCounter::KEY_USER_COUNT)
                ->where('period_start', $periodStart)
                ->where('period_end', $periodEnd)
                ->sum('value');

        $scanUsage = $scanOverride !== null
            ? (int) $scanOverride
            : UsageCounter::query()
                ->where('tenant_id', $subscription->tenant_id)
                ->where('key', UsageCounter::KEY_SCAN_COUNT)
                ->where('period_start', $periodStart)
                ->where('period_end', $periodEnd)
                ->sum('value');

        return [
            'user_count' => (int) $userUsage,
            'scan_count' => (int) $scanUsage,
        ];
    }

    private function clampDate(CarbonImmutable $value, CarbonImmutable $periodStart, CarbonImmutable $periodEndExclusive): CarbonImmutable
    {
        if ($value->lessThan($periodStart)) {
            return $periodStart;
        }

        if ($value->greaterThan($periodEndExclusive)) {
            return $periodEndExclusive;
        }

        return $value;
    }

    private function calculateProratedAmount(int $amount, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, ?CarbonImmutable $prorateUntil): int
    {
        if ($prorateUntil === null || $amount <= 0) {
            return max(0, $amount);
        }

        $periodEndExclusive = $periodEnd->addSecond();
        $effectiveUntil = $this->clampDate($prorateUntil, $periodStart, $periodEndExclusive);

        $totalSeconds = max(1, $periodEndExclusive->diffInSeconds($periodStart));
        $usedSeconds = $effectiveUntil->diffInSeconds($periodStart);

        if ($usedSeconds >= $totalSeconds) {
            return max(0, $amount);
        }

        $prorated = (int) round($amount * ($usedSeconds / $totalSeconds));

        return max(0, min($amount, $prorated));
    }

    /**
     * @param array<string, mixed> $limits
     */
    private function calculateTax(int $subtotal, array $limits): int
    {
        $configuredTaxRate = config('billing.tax_rate');

        if ($configuredTaxRate !== null) {
            $rate = (float) $configuredTaxRate;

            if ($rate <= 0) {
                return 0;
            }

            return (int) round($subtotal * $rate);
        }

        return max(0, (int) ($limits['tax_cents'] ?? 0));
    }
}
