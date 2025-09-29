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

        config(['tenant.id' => null]);

        $this->periodStart = CarbonImmutable::parse('2024-07-01T00:00:00Z');
        $this->periodEnd = $this->periodStart->endOfMonth();
    }

    public function test_preview_returns_breakdown(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->create([
            'price_cents' => 10000,
            'limits_json' => [
                'included_users' => 3,
                'included_scans' => 100,
                'user_overage_price_cents' => 500,
                'scan_overage_price_cents' => 2,
                'tax_cents' => 300,
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
            'value' => 5,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        UsageCounter::query()->create([
            'tenant_id' => $tenant->id,
            'key' => UsageCounter::KEY_SCAN_COUNT,
            'event_id' => null,
            'value' => 175,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $owner = $this->createTenantOwner($tenant);

        $response = $this->actingAs($owner, 'api')->postJson('/billing/preview', [], [
            'X-Tenant-ID' => $tenant->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.total_cents', 11450);
        $response->assertJsonPath('data.tax_cents', 300);
        $response->assertJsonCount(3, 'data.line_items');
        $response->assertJsonPath('data.line_items.0.type', 'base_plan');
        $response->assertJsonPath('data.line_items.1.type', 'user_overage');
        $response->assertJsonPath('data.line_items.2.type', 'scan_overage');
    }

    public function test_close_creates_invoice_with_line_items(): void
    {
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
        $this->assertSame(5000 + (3 * 400) + (30 * 3), $invoice->total_cents);
        $this->assertNotNull($invoice->issued_at);
        $this->assertNull($invoice->paid_at);
        $this->assertCount(3, $invoice->line_items_json);
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
}
