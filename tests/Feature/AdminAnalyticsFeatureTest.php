<?php

namespace Tests\Feature;

use App\Models\ActivityMetric;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Tenant;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class AdminAnalyticsFeatureTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    public function test_superadmin_receives_analytics_cards_with_occupancy_information(): void
    {
        config(['tenant.id' => null]);

        $tenant = Tenant::factory()->create();
        $superAdmin = $this->createSuperAdmin();

        $event = Event::factory()->for($tenant)->create([
            'status' => 'published',
            'capacity' => 100,
            'timezone' => 'UTC',
            'start_at' => CarbonImmutable::parse('2024-07-01T18:00:00Z'),
            'end_at' => CarbonImmutable::parse('2024-07-02T01:00:00Z'),
        ]);

        $guests = collect([
            ['name' => 'Alicia Analytics', 'rsvp_status' => 'confirmed'],
            ['name' => 'Bruno Boards', 'rsvp_status' => 'invited'],
        ])->map(function (array $data) use ($event) {
            return Guest::query()->create([
                'event_id' => $event->id,
                'full_name' => $data['name'],
                'rsvp_status' => $data['rsvp_status'],
                'allow_plus_ones' => false,
            ]);
        });

        $tickets = $guests->map(function (Guest $guest) use ($event) {
            return Ticket::query()->create([
                'event_id' => $event->id,
                'guest_id' => $guest->id,
                'type' => 'general',
                'price_cents' => 0,
                'status' => 'issued',
                'issued_at' => CarbonImmutable::parse('2024-07-01T17:00:00Z'),
            ]);
        });

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $tickets[0]->id,
            'guest_id' => $guests[0]->id,
            'result' => 'valid',
            'scanned_at' => CarbonImmutable::parse('2024-07-01T19:00:00Z'),
        ]);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $tickets[0]->id,
            'guest_id' => $guests[0]->id,
            'result' => 'duplicate',
            'scanned_at' => CarbonImmutable::parse('2024-07-01T19:05:00Z'),
        ]);

        ActivityMetric::query()->create([
            'event_id' => $event->id,
            'date_hour' => CarbonImmutable::parse('2024-07-01T19:00:00Z'),
            'invites_sent' => 120,
            'rsvp_confirmed' => 90,
            'scans_valid' => 1,
            'scans_duplicate' => 1,
            'unique_guests_in' => 1,
        ]);

        $response = $this->actingAs($superAdmin, 'api')
            ->getJson('/admin/analytics?from=2024-07-01&to=2024-07-02');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('meta.tenants.0.id', (string) $tenant->id);
        $response->assertJsonPath('data.0.overview.duplicates', 1);
        $response->assertJsonPath('data.0.overview.unique_attendees', 1);
        $this->assertEqualsWithDelta(0.01, $response->json('data.0.overview.occupancy_rate'), 0.0001);
        $this->assertSame('2024-07-01T19:00:00Z', $response->json('data.0.attendance.0.hour'));
    }
}
