<?php

namespace Tests\Feature;

use App\Models\ActivityMetric;
use App\Models\Attendance;
use App\Models\Checkpoint;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Qr;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class EventAnalyticsFeatureTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    public function test_event_analytics_endpoint_returns_paginated_datasets(): void
    {
        config(['tenant.id' => null]);

        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);

        $event = Event::factory()->for($tenant)->create([
            'status' => 'published',
            'capacity' => 80,
            'timezone' => 'UTC',
            'start_at' => CarbonImmutable::parse('2024-07-01T18:00:00Z'),
            'end_at' => CarbonImmutable::parse('2024-07-02T01:00:00Z'),
        ]);

        $venue = Venue::factory()->for($event)->create(['name' => 'Hall Principal']);
        $checkpoint = Checkpoint::factory()->for($event)->for($venue)->create(['name' => 'Acceso General']);

        $validGuest = Guest::query()->create([
            'event_id' => $event->id,
            'full_name' => 'Invitado Válido',
            'rsvp_status' => 'confirmed',
        ]);

        $duplicateGuest = Guest::query()->create([
            'event_id' => $event->id,
            'full_name' => 'Invitado Duplicado',
            'rsvp_status' => 'confirmed',
        ]);

        $invalidGuest = Guest::query()->create([
            'event_id' => $event->id,
            'full_name' => 'Invitado Invalidado',
            'rsvp_status' => 'invited',
        ]);

        $validTicket = Ticket::query()->create([
            'event_id' => $event->id,
            'guest_id' => $validGuest->id,
            'type' => 'vip',
            'price_cents' => 0,
            'status' => 'issued',
            'issued_at' => CarbonImmutable::parse('2024-07-01T17:00:00Z'),
        ]);

        $duplicateTicket = Ticket::query()->create([
            'event_id' => $event->id,
            'guest_id' => $duplicateGuest->id,
            'type' => 'vip',
            'price_cents' => 0,
            'status' => 'issued',
            'issued_at' => CarbonImmutable::parse('2024-07-01T17:05:00Z'),
        ]);

        $invalidTicket = Ticket::query()->create([
            'event_id' => $event->id,
            'guest_id' => $invalidGuest->id,
            'type' => 'general',
            'price_cents' => 0,
            'status' => 'issued',
            'issued_at' => CarbonImmutable::parse('2024-07-01T17:10:00Z'),
        ]);

        Qr::query()->create([
            'ticket_id' => $duplicateTicket->id,
            'display_code' => 'MT-DUP-0001',
            'payload' => 'MT-DUP-0001',
            'version' => 1,
            'is_active' => true,
        ]);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $validTicket->id,
            'guest_id' => $validGuest->id,
            'checkpoint_id' => $checkpoint->id,
            'result' => 'valid',
            'scanned_at' => CarbonImmutable::parse('2024-07-01T19:00:00Z'),
        ]);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $duplicateTicket->id,
            'guest_id' => $duplicateGuest->id,
            'checkpoint_id' => $checkpoint->id,
            'result' => 'valid',
            'scanned_at' => CarbonImmutable::parse('2024-07-01T19:05:00Z'),
        ]);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $duplicateTicket->id,
            'guest_id' => $duplicateGuest->id,
            'checkpoint_id' => $checkpoint->id,
            'result' => 'duplicate',
            'scanned_at' => CarbonImmutable::parse('2024-07-01T19:06:00Z'),
        ]);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $invalidTicket->id,
            'guest_id' => $invalidGuest->id,
            'checkpoint_id' => $checkpoint->id,
            'result' => 'invalid',
            'scanned_at' => CarbonImmutable::parse('2024-07-01T19:07:00Z'),
        ]);

        ActivityMetric::query()->create([
            'event_id' => $event->id,
            'date_hour' => CarbonImmutable::parse('2024-07-01T19:00:00Z'),
            'invites_sent' => 200,
            'rsvp_confirmed' => 140,
            'scans_valid' => 2,
            'scans_duplicate' => 1,
            'unique_guests_in' => 2,
        ]);

        ActivityMetric::query()->create([
            'event_id' => $event->id,
            'date_hour' => CarbonImmutable::parse('2024-07-01T20:00:00Z'),
            'invites_sent' => 200,
            'rsvp_confirmed' => 140,
            'scans_valid' => 1,
            'scans_duplicate' => 0,
            'unique_guests_in' => 1,
        ]);

        $response = $this->actingAs($organizer, 'api')
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson(sprintf(
                '/events/%s/analytics?hour_per_page=1&hour_page=1&checkpoint_per_page=1&duplicates_per_page=1&errors_per_page=1',
                $event->id
            ));

        $response->assertOk();
        $response->assertJsonPath('data.hourly.meta.total', 2);
        $this->assertSame('2024-07-01T19:00:00Z', $response->json('data.hourly.data.0.hour'));
        $this->assertSame('Acceso General', $response->json('data.checkpoints.data.0.name'));
        $this->assertSame(2, $response->json('data.checkpoints.totals.valid'));
        $this->assertSame(1, $response->json('data.duplicates.data.0.occurrences'));
        $this->assertSame('invalid', $response->json('data.errors.data.0.result'));
    }
}
