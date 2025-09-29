<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\HostessAssignment;
use App\Models\Plan;
use App\Models\Qr;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\UsageCounter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesUsers;
use Tests\Support\Payloads\EventPayloadFactory;
use Tests\TestCase;

class EnforceLimitsMiddlewareTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_event_creation_returns_payment_required_when_event_limit_reached(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2024-07-10T00:00:00Z'));

        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->create([
            'limits_json' => [
                'max_events' => 1,
            ],
        ]);
        Subscription::factory()->for($tenant)->for($plan)->create([
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $organizer = $this->createOrganizer($tenant);

        $periodStart = CarbonImmutable::now()->startOfMonth();
        $periodEnd = CarbonImmutable::now()->endOfMonth();

        UsageCounter::query()->create([
            'tenant_id' => $tenant->id,
            'key' => UsageCounter::KEY_EVENT_COUNT,
            'value' => 1,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);

        $payload = EventPayloadFactory::make($tenant, $organizer, [
            'code' => 'LIMITED-1',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($organizer, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->postJson('/events', $payload);

        $response->assertStatus(402);
        $response->assertJsonPath('error.code', 'LIMIT_EXCEEDED');
        $response->assertJsonPath('error.details.limit', 1);
        $response->assertJsonPath('error.details.resource', 'events');
    }

    public function test_user_creation_returns_payment_required_when_user_limit_reached(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2024-07-10T00:00:00Z'));

        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->create([
            'limits_json' => [
                'max_users' => 2,
            ],
        ]);
        Subscription::factory()->for($tenant)->for($plan)->create([
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $hostessRole = Role::factory()->create([
            'code' => 'hostess',
            'tenant_id' => $tenant->id,
        ]);

        $periodStart = CarbonImmutable::now()->startOfMonth();
        $periodEnd = CarbonImmutable::now()->endOfMonth();

        UsageCounter::query()->create([
            'tenant_id' => $tenant->id,
            'key' => UsageCounter::KEY_USER_COUNT,
            'value' => 2,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);

        $superAdmin = $this->createSuperAdmin();

        $payload = [
            'tenant_id' => $tenant->id,
            'name' => 'Limit Hit User',
            'email' => 'limit-user@example.com',
            'password' => 'securePass123',
            'roles' => ['hostess'],
            'is_active' => true,
        ];

        $response = $this->actingAs($superAdmin, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->postJson('/users', $payload);

        $response->assertStatus(402);
        $response->assertJsonPath('error.code', 'LIMIT_EXCEEDED');
        $response->assertJsonPath('error.details.limit', 2);
        $response->assertJsonPath('error.details.resource', 'users');

        $this->assertDatabaseMissing('users', ['email' => 'limit-user@example.com']);
        $this->assertDatabaseMissing('user_roles', ['role_id' => $hostessRole->id, 'tenant_id' => $tenant->id]);
    }

    public function test_scan_limit_returns_payment_required_and_logs_warning(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2024-07-10T00:00:00Z'));

        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->create([
            'limits_json' => [
                'max_scans_per_event' => 1,
            ],
        ]);
        Subscription::factory()->for($tenant)->for($plan)->create([
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $organizer = $this->createOrganizer($tenant);
        $hostess = $this->createHostess($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
            'checkin_policy' => 'single',
        ]);

        HostessAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'hostess_user_id' => $hostess->id,
            'event_id' => $event->id,
            'venue_id' => null,
            'checkpoint_id' => null,
            'starts_at' => CarbonImmutable::now()->subHour(),
            'ends_at' => CarbonImmutable::now()->addHour(),
            'is_active' => true,
        ]);

        $ticket = Ticket::query()->create([
            'event_id' => $event->id,
            'guest_id' => $event->guests()->create(['full_name' => 'Scan Limited Guest'])->id,
            'type' => 'general',
            'price_cents' => 0,
            'status' => 'issued',
            'issued_at' => CarbonImmutable::now(),
            'expires_at' => null,
        ]);

        $qr = Qr::query()->create([
            'ticket_id' => $ticket->id,
            'code' => 'QR-' . Str::upper(Str::random(8)),
            'version' => 1,
            'is_active' => true,
        ]);

        $periodStart = CarbonImmutable::now()->startOfMonth();
        $periodEnd = CarbonImmutable::now()->endOfMonth();

        UsageCounter::query()->create([
            'tenant_id' => $tenant->id,
            'event_id' => $event->id,
            'key' => UsageCounter::KEY_SCAN_COUNT,
            'value' => 1,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);

        Log::spy();

        $response = $this->actingAs($hostess, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->postJson('/scan', [
                'qr_code' => $qr->code,
                'scanned_at' => CarbonImmutable::now()->toIso8601String(),
            ]);

        $response->assertStatus(402);
        $response->assertJsonPath('error.code', 'LIMIT_EXCEEDED');
        $response->assertJsonPath('error.details.event_id', $event->id);
        $response->assertJsonPath('error.details.limit', 1);
        $response->assertJsonPath('error.details.suggestion');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($tenant, $event): bool {
                return $message === 'limits.scan_exceeded'
                    && $context['tenant_id'] === (string) $tenant->id
                    && $context['event_id'] === (string) $event->id
                    && $context['limit'] === 1
                    && $context['current_usage'] === 1
                    && isset($context['suggestion']);
            });
    }

    public function test_csv_export_returns_forbidden_when_feature_disabled(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->create([
            'features_json' => [
                'exports' => [
                    'csv' => false,
                ],
            ],
        ]);
        Subscription::factory()->for($tenant)->for($plan)->create([
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $organizer = $this->createOrganizer($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
        ]);

        $response = $this->actingAs($organizer, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->getJson(sprintf('/events/%s/reports/attendance.csv', $event->id));

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FEATURE_NOT_AVAILABLE');
        $response->assertJsonPath('error.details.feature', 'exports.csv');
    }
}
