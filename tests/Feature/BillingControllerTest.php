<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\UsageCounter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class BillingControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    private CarbonImmutable $periodStart;
    private CarbonImmutable $periodEnd;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tenant.id' => null,
            'billing.tax_rate' => null,
        ]);

        $this->periodStart = CarbonImmutable::parse('2024-07-01T00:00:00Z');
        $this->periodEnd = $this->periodStart->endOfMonth();
    }

    public function test_preview_returns_prorated_breakdown_with_simulated_usage(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->create([
            'price_cents' => 10000,
            'limits_json' => [
                'included_users' => 3,
                'included_scans' => 100,
                'user_overage_price_cents' => 500,
                'scan_overage_price_cents' => 2,
                'tax_cents' => 0,
            ],
        ]);

        $subscription = Subscription::factory()
            ->for($tenant)
            ->for($plan)
            ->create([
                'current_period_start' => $this->periodStart,
                'current_period_end' => $this->periodEnd,
            ]);

        $owner = $this->createTenantOwner($tenant);

        $closeAt = $this->periodStart->addDays(15)->addHours(12);
        $expectedPlanAmount = $this->proratedAmount(
            $plan->price_cents,
            $this->periodStart,
            $this->periodEnd,
            $closeAt
        );

        $expectedSubtotal = $expectedPlanAmount + (4 * 500) + (150 * 2);

        $response = $this->actingAs($owner, 'api')->postJson('/billing/preview', [
            'close_at' => $closeAt->toIso8601String(),
            'simulate' => [
                'user_count' => 7,
                'scan_count' => 250,
            ],
        ], [
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.subtotal_cents', $expectedSubtotal);
        $response->assertJsonPath('data.tax_cents', 0);
        $response->assertJsonPath('data.total_cents', $expectedSubtotal);
        $response->assertJsonCount(3, 'data.line_items');
        $response->assertJsonPath('data.line_items.0.type', 'base_plan');
        $response->assertJsonPath('data.line_items.0.amount_cents', $expectedPlanAmount);
        $response->assertJsonPath('data.line_items.1.type', 'user_overage');
        $response->assertJsonPath('data.line_items.1.quantity', 4);
        $response->assertJsonPath('data.line_items.2.type', 'scan_overage');
        $response->assertJsonPath('data.line_items.2.quantity', 150);
    }

    public function test_close_creates_invoice_with_line_items(): void
    {
        config(['billing.tax_rate' => 0.19]);

        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->create([
            'price_cents' => 5000,
            'limits_json' => [
                'included_users' => 1,
                'included_scans' => 50,
                'user_overage_price_cents' => 400,
                'scan_overage_price_cents' => 3,
                'tax_cents' => 0,
            ],
        ]);

        $subscription = Subscription::factory()
            ->for($tenant)
            ->for($plan)
            ->create([
                'current_period_start' => $this->periodStart,
                'current_period_end' => $this->periodEnd,
            ]);

        UsageCounter::query()->create([
            'tenant_id' => $tenant->id,
            'key' => UsageCounter::KEY_USER_COUNT,
            'event_id' => null,
            'value' => 4,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        UsageCounter::query()->create([
            'tenant_id' => $tenant->id,
            'key' => UsageCounter::KEY_SCAN_COUNT,
            'event_id' => null,
            'value' => 80,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $owner = $this->createTenantOwner($tenant);

        $response = $this->actingAs($owner, 'api')->postJson('/billing/invoices/close', [], [
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertCreated();

        $invoice = Invoice::query()->first();
        $this->assertNotNull($invoice);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->status);
        $expectedSubtotal = 5000 + (3 * 400) + (30 * 3);
        $expectedTax = (int) round($expectedSubtotal * 0.19);
        $this->assertSame($expectedSubtotal, $invoice->subtotal_cents);
        $this->assertSame($expectedTax, $invoice->tax_cents);
        $this->assertSame($expectedSubtotal + $expectedTax, $invoice->total_cents);
        $this->assertNotNull($invoice->issued_at);
        $this->assertNull($invoice->paid_at);
        $this->assertCount(3, $invoice->line_items_json);
    }

    public function test_close_ignores_usage_outside_current_period(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->create([
            'price_cents' => 5000,
            'limits_json' => [
                'included_users' => 2,
                'included_scans' => 10,
                'user_overage_price_cents' => 1000,
                'scan_overage_price_cents' => 5,
                'tax_cents' => 0,
            ],
        ]);

        Subscription::factory()
            ->for($tenant)
            ->for($plan)
            ->create([
                'current_period_start' => $this->periodStart,
                'current_period_end' => $this->periodEnd,
            ]);

        UsageCounter::query()->create([
            'tenant_id' => $tenant->id,
            'key' => UsageCounter::KEY_USER_COUNT,
            'event_id' => null,
            'value' => 4,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        UsageCounter::query()->create([
            'tenant_id' => $tenant->id,
            'key' => UsageCounter::KEY_SCAN_COUNT,
            'event_id' => null,
            'value' => 18,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $previousPeriodStart = $this->periodStart->subMonth()->startOfMonth();
        $previousPeriodEnd = $previousPeriodStart->endOfMonth();

        UsageCounter::query()->create([
            'tenant_id' => $tenant->id,
            'key' => UsageCounter::KEY_USER_COUNT,
            'event_id' => null,
            'value' => 25,
            'period_start' => $previousPeriodStart,
            'period_end' => $previousPeriodEnd,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $nextPeriodStart = $this->periodStart->addMonth()->startOfMonth();
        $nextPeriodEnd = $nextPeriodStart->endOfMonth();

        UsageCounter::query()->create([
            'tenant_id' => $tenant->id,
            'key' => UsageCounter::KEY_SCAN_COUNT,
            'event_id' => null,
            'value' => 50,
            'period_start' => $nextPeriodStart,
            'period_end' => $nextPeriodEnd,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $owner = $this->createTenantOwner($tenant);

        $response = $this->actingAs($owner, 'api')->postJson('/billing/invoices/close', [], [
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.period_start', $this->periodStart->toIso8601String());
        $response->assertJsonPath('data.period_end', $this->periodEnd->toIso8601String());
        $response->assertJsonPath('data.total_cents', 7040);
        $response->assertJsonCount(3, 'data.line_items');
        $response->assertJsonPath('data.line_items.0.type', 'base_plan');
        $response->assertJsonPath('data.line_items.1.quantity', 2); // 4 users - 2 included
        $response->assertJsonPath('data.line_items.2.quantity', 8); // 18 scans - 10 included

        $invoice = Invoice::query()->first();
        $this->assertNotNull($invoice);
        $this->assertSame(7040, $invoice->total_cents);
        $this->assertCount(3, $invoice->line_items_json);
    }

    public function test_close_returns_error_when_period_already_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->create([
            'price_cents' => 4500,
            'limits_json' => [
                'included_users' => 2,
                'included_scans' => 20,
                'user_overage_price_cents' => 400,
                'scan_overage_price_cents' => 3,
                'tax_cents' => 0,
            ],
        ]);

        Subscription::factory()
            ->for($tenant)
            ->for($plan)
            ->create([
                'current_period_start' => $this->periodStart,
                'current_period_end' => $this->periodEnd,
            ]);

        UsageCounter::query()->create([
            'tenant_id' => $tenant->id,
            'key' => UsageCounter::KEY_USER_COUNT,
            'event_id' => null,
            'value' => 3,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $owner = $this->createTenantOwner($tenant);

        $this->actingAs($owner, 'api')->postJson('/billing/invoices/close', [], [
            'X-Tenant-ID' => $tenant->id,
        ])->assertCreated();

        $invoice = Invoice::query()->firstOrFail();

        $response = $this->actingAs($owner, 'api')->postJson('/billing/invoices/close', [], [
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertStatus(HttpResponse::HTTP_CONFLICT);
        $response->assertJsonPath('error.code', 'billing_period_closed');
        $response->assertJsonPath('error.details.invoice.id', (string) $invoice->id);
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_pay_marks_invoice_as_paid_and_records_payment(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->create([
            'price_cents' => 4000,
            'limits_json' => [
                'included_users' => 2,
                'included_scans' => 0,
                'user_overage_price_cents' => 500,
                'scan_overage_price_cents' => 0,
                'tax_cents' => 0,
            ],
        ]);

        $subscription = Subscription::factory()
            ->for($tenant)
            ->for($plan)
            ->create([
                'current_period_start' => $this->periodStart,
                'current_period_end' => $this->periodEnd,
            ]);

        UsageCounter::query()->create([
            'tenant_id' => $tenant->id,
            'key' => UsageCounter::KEY_USER_COUNT,
            'event_id' => null,
            'value' => 3,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $owner = $this->createTenantOwner($tenant);

        $this->actingAs($owner, 'api')->postJson('/billing/invoices/close', [], [
            'X-Tenant-ID' => $tenant->id,
        ])->assertCreated();

        $invoice = Invoice::query()->firstOrFail();

        $response = $this->actingAs($owner, 'api')->postJson(
            sprintf('/billing/invoices/%s/pay', $invoice->id),
            [],
            ['X-Tenant-ID' => $tenant->id]
        );

        $response->assertOk();

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertNotNull($invoice->paid_at);
        $this->assertTrue($invoice->payments()->exists());

        $payment = Payment::query()->first();
        $this->assertNotNull($payment);
        $this->assertSame('stub', $payment->provider);
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame($invoice->total_cents, $payment->amount_cents);
    }

    private function proratedAmount(int $amount, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, CarbonImmutable $closeAt): int
    {
        $periodEndExclusive = $periodEnd->addSecond();
        $effectiveUntil = $closeAt;

        if ($effectiveUntil->lessThan($periodStart)) {
            $effectiveUntil = $periodStart;
        }

        if ($effectiveUntil->greaterThan($periodEndExclusive)) {
            $effectiveUntil = $periodEndExclusive;
        }

        $totalSeconds = max(1, $periodEndExclusive->diffInSeconds($periodStart));
        $usedSeconds = $effectiveUntil->diffInSeconds($periodStart);

        if ($usedSeconds >= $totalSeconds) {
            return max(0, $amount);
        }

        return max(0, min($amount, (int) round($amount * ($usedSeconds / $totalSeconds))));
    }

}
