<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\UsageCounter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class TenantOverviewTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    public function test_organizer_can_view_tenant_overview(): void
    {
        $periodStart = CarbonImmutable::parse('2024-07-01T00:00:00Z');
        $periodEnd = $periodStart->endOfMonth();

        $plan = Plan::factory()->create([
            'price_cents' => 12900,
            'limits_json' => [
                'max_events' => 5,
                'max_users' => 10,
                'max_scans_per_event' => 500,
                'included_users' => 3,
                'included_scans' => 120,
            ],
        ]);

        $tenant = Tenant::factory()->create([
            'plan' => $plan->code,
            'settings_json' => [
                'limits_override' => [
                    'max_users' => 15,
                ],
            ],
        ]);

        $subscription = Subscription::factory()
            ->for($tenant)
            ->for($plan)
            ->create([
                'status' => Subscription::STATUS_ACTIVE,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
            ]);

        $eventA = Event::factory()->for($tenant)->create(['name' => 'Summer Launch']);
        $eventB = Event::factory()->for($tenant)->create(['name' => 'VIP Party']);

        UsageCounter::query()->create([
            'tenant_id' => $tenant->id,
            'key' => UsageCounter::KEY_EVENT_COUNT,
            'event_id' => null,
            'value' => 3,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);

        UsageCounter::query()->create([
            'tenant_id' => $tenant->id,
            'key' => UsageCounter::KEY_USER_COUNT,
            'event_id' => null,
            'value' => 9,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);

        UsageCounter::query()->create([
            'tenant_id' => $tenant->id,
            'key' => UsageCounter::KEY_SCAN_COUNT,
            'event_id' => $eventA->id,
            'value' => 320,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);

        UsageCounter::query()->create([
            'tenant_id' => $tenant->id,
            'key' => UsageCounter::KEY_SCAN_COUNT,
            'event_id' => $eventB->id,
            'value' => 180,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);

        Invoice::query()->create([
            'tenant_id' => $tenant->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'subtotal_cents' => 20000,
            'tax_cents' => 3800,
            'total_cents' => 23800,
            'status' => Invoice::STATUS_PENDING,
            'issued_at' => $periodEnd->addDay(),
            'due_at' => $periodEnd->addDays(15),
            'line_items_json' => [
                [
                    'type' => 'base_plan',
                    'description' => 'Plan mensual',
                    'quantity' => 1,
                    'unit_price_cents' => 20000,
                    'amount_cents' => 20000,
                ],
            ],
        ]);

        $organizer = $this->createOrganizer($tenant);

        $response = $this->actingAs($organizer, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->getJson('/tenants/me/overview');

        $response->assertOk();

        $response->assertJsonPath('data.plan.code', $plan->code);
        $response->assertJsonPath('data.plan.price_cents', 12900);
        $response->assertJsonPath('data.subscription.status', Subscription::STATUS_ACTIVE);
        $response->assertJsonPath('data.usage.event_count', 3);
        $response->assertJsonPath('data.usage.user_count', 9);
        $response->assertJsonPath('data.usage.scan_count', 500);
        $response->assertJsonPath('data.scan_breakdown.0.event_id', (string) $eventA->id);
        $response->assertJsonPath('data.scan_breakdown.0.value', 320);
        $response->assertJsonPath('data.latest_invoice.total_cents', 23800);
        $response->assertJsonPath('data.effective_limits.max_users', 15);
    }
}
