<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\UsageCounter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class AdminTenantControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenant.id' => null]);
    }

    public function test_superadmin_can_create_tenant_with_subscription(): void
    {
        $plan = Plan::factory()->create([
            'code' => 'pro',
            'billing_cycle' => 'monthly',
        ]);

        $superAdmin = $this->createSuperAdmin();

        $payload = [
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
            'plan_id' => $plan->id,
            'trial_days' => 14,
            'limit_overrides' => [
                'max_events' => 20,
            ],
        ];

        $response = $this->actingAs($superAdmin, 'api')->postJson('/admin/tenants', $payload);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Acme Corp');
        $response->assertJsonPath('data.plan.code', 'pro');
        $response->assertJsonPath('data.subscription.status', Subscription::STATUS_TRIALING);
        $response->assertJsonPath('data.limits_override.max_events', 20);

        $tenantId = $response->json('data.id');

        $this->assertDatabaseHas('tenants', [
            'id' => $tenantId,
            'plan' => 'pro',
        ]);

        $this->assertDatabaseHas('subscriptions', [
            'tenant_id' => $tenantId,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_TRIALING,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'tenant',
            'entity_id' => $tenantId,
            'action' => 'created',
        ]);
    }

    public function test_superadmin_can_update_tenant_plan_and_overrides(): void
    {
        $planA = Plan::factory()->create(['code' => 'basic']);
        $planB = Plan::factory()->create(['code' => 'enterprise']);

        $tenant = Tenant::factory()->create([
            'plan' => $planA->code,
            'settings_json' => null,
        ]);

        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $planA->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $tenant->setRelation('latestSubscription', $subscription);

        $superAdmin = $this->createSuperAdmin();

        $payload = [
            'plan_id' => $planB->id,
            'subscription_status' => Subscription::STATUS_PAUSED,
            'limit_overrides' => [
                'max_users' => 50,
            ],
        ];

        $response = $this->actingAs($superAdmin, 'api')
            ->patchJson(sprintf('/admin/tenants/%s', $tenant->id), $payload);

        $response->assertOk();
        $response->assertJsonPath('data.plan.code', 'enterprise');
        $response->assertJsonPath('data.subscription.status', Subscription::STATUS_PAUSED);
        $response->assertJsonPath('data.limits_override.max_users', 50);

        $tenant->refresh();
        $subscription->refresh();

        $this->assertSame('enterprise', $tenant->plan);
        $this->assertSame(Subscription::STATUS_PAUSED, $subscription->status);
        $this->assertSame($planB->id, $subscription->plan_id);
        $this->assertSame(['max_users' => 50], $tenant->limitOverrides());

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'tenant',
            'entity_id' => $tenant->id,
            'action' => 'updated',
        ]);
    }

    public function test_superadmin_can_list_tenants_with_usage_filters(): void
    {
        $now = CarbonImmutable::now()->startOfMonth();
        CarbonImmutable::setTestNow($now);

        $plan = Plan::factory()->create([
            'code' => 'basic',
            'limits_json' => [
                'max_events' => 5,
                'max_users' => 10,
            ],
        ]);

        $tenantActive = Tenant::factory()->create(['plan' => $plan->code]);
        $tenantPaused = Tenant::factory()->create(['plan' => $plan->code]);

        $subscriptionActive = Subscription::factory()->create([
            'tenant_id' => $tenantActive->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => $now,
            'current_period_end' => $now->endOfMonth(),
        ]);

        Subscription::factory()->create([
            'tenant_id' => $tenantPaused->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_PAUSED,
            'current_period_start' => $now,
            'current_period_end' => $now->endOfMonth(),
        ]);

        $event = Event::factory()->create(['tenant_id' => $tenantActive->id]);

        UsageCounter::create([
            'tenant_id' => $tenantActive->id,
            'key' => UsageCounter::KEY_EVENT_COUNT,
            'value' => 3,
            'period_start' => $now,
            'period_end' => $now->endOfMonth(),
        ]);

        UsageCounter::create([
            'tenant_id' => $tenantActive->id,
            'key' => UsageCounter::KEY_USER_COUNT,
            'value' => 8,
            'period_start' => $now,
            'period_end' => $now->endOfMonth(),
        ]);

        UsageCounter::create([
            'tenant_id' => $tenantActive->id,
            'event_id' => $event->id,
            'key' => UsageCounter::KEY_SCAN_COUNT,
            'value' => 25,
            'period_start' => $now,
            'period_end' => $now->endOfMonth(),
        ]);

        $tenantActive->setRelation('latestSubscription', $subscriptionActive);

        $superAdmin = $this->createSuperAdmin();

        $response = $this->actingAs($superAdmin, 'api')
            ->getJson('/admin/tenants?status=active&search=' . $tenantActive->name);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $tenantActive->id);
        $response->assertJsonPath('data.0.usage.event_count', 3);
        $response->assertJsonPath('data.0.usage.user_count', 8);
        $response->assertJsonPath('data.0.usage.scan_count', 25);
        $response->assertJsonPath('data.0.effective_limits.max_events', 5);

        CarbonImmutable::setTestNow();
    }

    public function test_usage_endpoint_returns_series_with_event_breakdown(): void
    {
        $now = CarbonImmutable::parse('2024-05-01T00:00:00Z');
        CarbonImmutable::setTestNow($now);

        $plan = Plan::factory()->create([
            'code' => 'standard',
            'limits_json' => [
                'max_events' => 10,
                'max_users' => 20,
            ],
        ]);
        $tenant = Tenant::factory()->create(['plan' => $plan->code]);

        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => $now,
            'current_period_end' => $now->endOfMonth(),
        ]);
        $tenant->setRelation('latestSubscription', $subscription);

        $eventA = Event::factory()->create(['tenant_id' => $tenant->id]);
        $eventB = Event::factory()->create(['tenant_id' => $tenant->id]);

        $periods = [
            CarbonImmutable::parse('2024-04-01T00:00:00Z'),
            CarbonImmutable::parse('2024-05-01T00:00:00Z'),
        ];

        foreach ($periods as $periodStart) {
            $periodEnd = $periodStart->endOfMonth();

            UsageCounter::create([
                'tenant_id' => $tenant->id,
                'key' => UsageCounter::KEY_EVENT_COUNT,
                'value' => 2,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);

            UsageCounter::create([
                'tenant_id' => $tenant->id,
                'key' => UsageCounter::KEY_USER_COUNT,
                'value' => 10,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);

            UsageCounter::create([
                'tenant_id' => $tenant->id,
                'event_id' => $eventA->id,
                'key' => UsageCounter::KEY_SCAN_COUNT,
                'value' => 15,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);

            UsageCounter::create([
                'tenant_id' => $tenant->id,
                'event_id' => $eventB->id,
                'key' => UsageCounter::KEY_SCAN_COUNT,
                'value' => 5,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);
        }

        $superAdmin = $this->createSuperAdmin();

        $response = $this->actingAs($superAdmin, 'api')
            ->getJson(sprintf('/admin/tenants/%s/usage?from=2024-04-01&to=2024-05-31', $tenant->id));

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.scan_total', 20);
        $response->assertJsonPath('data.0.scan_breakdown.0.event_id', $eventA->id);
        $response->assertJsonPath('data.0.scan_breakdown.0.value', 15);
        $response->assertJsonPath('data.1.scan_breakdown.1.event_id', $eventB->id);
        $response->assertJsonPath('data.1.user_count', 10);
        $response->assertJsonPath('meta.tenant.id', $tenant->id);
        $response->assertJsonPath('meta.tenant.plan.code', $plan->code);
        $response->assertJsonPath('meta.requested_period.from', '2024-04-01T00:00:00+00:00');
        $response->assertJsonPath('meta.requested_period.to', '2024-05-31T23:59:59+00:00');

        CarbonImmutable::setTestNow();
    }
}
