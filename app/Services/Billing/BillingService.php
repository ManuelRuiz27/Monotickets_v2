<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\UsageCounter;
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
    public function preview(Subscription $subscription): array
    {
        $periodStart = $subscription->current_period_start instanceof CarbonImmutable
            ? $subscription->current_period_start
            : CarbonImmutable::parse((string) $subscription->current_period_start);
        $periodEnd = $subscription->current_period_end instanceof CarbonImmutable
            ? $subscription->current_period_end
            : CarbonImmutable::parse((string) $subscription->current_period_end);

        return $this->buildInvoiceData($subscription, $periodStart, $periodEnd);
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
            return $existingInvoice;
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
    private function buildInvoiceData(Subscription $subscription, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $plan = $subscription->plan;
        $limits = $plan->limits_json ?? [];

        $lineItems = Collection::make();

        $baseDescription = sprintf('Licencia %s (%s)', $plan->name, Str::upper($plan->billing_cycle));
        $planAmount = (int) $plan->price_cents;

        $lineItems->push([
            'type' => 'base_plan',
            'description' => $baseDescription,
            'quantity' => 1,
            'unit_price_cents' => $planAmount,
            'amount_cents' => $planAmount,
        ]);

        $usage = $this->resolveUsage($subscription, $periodStart, $periodEnd);

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
        $tax = max(0, (int) ($limits['tax_cents'] ?? 0));
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
    private function resolveUsage(Subscription $subscription, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $userUsage = UsageCounter::query()
            ->where('tenant_id', $subscription->tenant_id)
            ->where('key', UsageCounter::KEY_USER_COUNT)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->sum('value');

        $scanUsage = UsageCounter::query()
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
}
