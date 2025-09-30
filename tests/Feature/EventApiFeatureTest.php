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
use App\Services\Qr\QrCodeProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class EventApiFeatureTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    public function test_events_index_and_show_include_occupancy_metrics(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
            'capacity' => 100,
        ]);

        [$firstTicket] = $this->createTicketWithQr($event, ['guest_name' => 'Guest One']);
        [$secondTicket] = $this->createTicketWithQr($event, ['guest_name' => 'Guest Two']);
        $this->createTicketWithQr($event, ['guest_name' => 'Guest Three']);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $firstTicket->id,
            'guest_id' => $firstTicket->guest_id,
            'checkpoint_id' => null,
            'hostess_user_id' => null,
            'result' => 'valid',
            'scanned_at' => CarbonImmutable::parse('2024-07-01T10:00:00Z'),
            'device_id' => 'device-a',
            'offline' => false,
            'metadata_json' => [],
        ]);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $secondTicket->id,
            'guest_id' => $secondTicket->guest_id,
            'checkpoint_id' => null,
            'hostess_user_id' => null,
            'result' => 'valid',
            'scanned_at' => CarbonImmutable::parse('2024-07-01T10:05:00Z'),
            'device_id' => 'device-b',
            'offline' => false,
            'metadata_json' => [],
        ]);

        $indexResponse = $this->actingAs($organizer, 'api')->getJson('/events');
        $indexResponse->assertOk();

        $this->assertSame(2, $indexResponse->json('data.0.attendances_count'));
        $this->assertSame(3, $indexResponse->json('data.0.tickets_issued'));
        $this->assertSame(2, $indexResponse->json('data.0.capacity_used'));
        $this->assertEqualsWithDelta(0.02, $indexResponse->json('data.0.occupancy_percent'), 0.0001);

        $showResponse = $this->actingAs($organizer, 'api')->getJson(sprintf('/events/%s', $event->id));
        $showResponse->assertOk();

        $this->assertSame(2, $showResponse->json('data.attendances_count'));
        $this->assertSame(3, $showResponse->json('data.tickets_issued'));
        $this->assertSame(2, $showResponse->json('data.capacity_used'));
        $this->assertEqualsWithDelta(0.02, $showResponse->json('data.occupancy_percent'), 0.0001);
    }

    public function test_event_analytics_endpoint_returns_paginated_datasets(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
        ]);

        $venue = Venue::factory()->for($event)->create();
        $checkpointA = Checkpoint::factory()->for($event)->for($venue)->create(['name' => 'Main Gate']);
        $checkpointB = Checkpoint::factory()->for($event)->for($venue)->create(['name' => 'VIP Gate']);

        [$primaryTicket] = $this->createTicketWithQr($event, ['guest_name' => 'Primary Guest']);
        [$secondaryTicket] = $this->createTicketWithQr($event, ['guest_name' => 'Secondary Guest']);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $primaryTicket->id,
            'guest_id' => $primaryTicket->guest_id,
            'checkpoint_id' => $checkpointA->id,
            'hostess_user_id' => null,
            'result' => 'valid',
            'scanned_at' => CarbonImmutable::parse('2024-07-01T09:00:00Z'),
            'device_id' => 'sync-1',
            'offline' => true,
            'metadata_json' => [],
        ]);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $primaryTicket->id,
            'guest_id' => $primaryTicket->guest_id,
            'checkpoint_id' => $checkpointA->id,
            'hostess_user_id' => null,
            'result' => 'duplicate',
            'scanned_at' => CarbonImmutable::parse('2024-07-01T09:05:00Z'),
            'device_id' => 'sync-1',
            'offline' => true,
            'metadata_json' => [],
        ]);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $primaryTicket->id,
            'guest_id' => $primaryTicket->guest_id,
            'checkpoint_id' => $checkpointA->id,
            'hostess_user_id' => null,
            'result' => 'duplicate',
            'scanned_at' => CarbonImmutable::parse('2024-07-01T09:06:00Z'),
            'device_id' => 'sync-1',
            'offline' => true,
            'metadata_json' => [],
        ]);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $secondaryTicket->id,
            'guest_id' => $secondaryTicket->guest_id,
            'checkpoint_id' => $checkpointB->id,
            'hostess_user_id' => null,
            'result' => 'invalid',
            'scanned_at' => CarbonImmutable::parse('2024-07-01T10:00:00Z'),
            'device_id' => 'sync-2',
            'offline' => true,
            'metadata_json' => ['reason' => 'event_mismatch'],
        ]);

        ActivityMetric::query()->create([
            'event_id' => $event->id,
            'date_hour' => CarbonImmutable::parse('2024-07-01T09:00:00Z'),
            'invites_sent' => 10,
            'rsvp_confirmed' => 5,
            'scans_valid' => 1,
            'scans_duplicate' => 2,
            'unique_guests_in' => 1,
        ]);

        ActivityMetric::query()->create([
            'event_id' => $event->id,
            'date_hour' => CarbonImmutable::parse('2024-07-01T10:00:00Z'),
            'invites_sent' => 0,
            'rsvp_confirmed' => 0,
            'scans_valid' => 0,
            'scans_duplicate' => 0,
            'unique_guests_in' => 0,
        ]);

        $response = $this->actingAs($organizer, 'api')->getJson(sprintf(
            '/events/%s/analytics?hour_per_page=1&duplicates_per_page=5&errors_per_page=5&checkpoint_per_page=5',
            $event->id
        ));

        $response->assertOk();

        $this->assertSame(2, $response->json('data.hourly.meta.total'));
        $this->assertSame(1, $response->json('data.hourly.meta.per_page'));
        $this->assertSame(1, $response->json('data.hourly.data.0.valid'));
        $this->assertSame(2, $response->json('data.checkpoints.totals.duplicate'));

        $checkpointEntry = collect($response->json('data.checkpoints.data'))
            ->firstWhere('checkpoint_id', $checkpointA->id);
        $this->assertNotNull($checkpointEntry);
        $this->assertSame(1, $checkpointEntry['valid']);
        $this->assertSame(2, $checkpointEntry['duplicate']);

        $duplicateEntry = $response->json('data.duplicates.data.0');
        $this->assertSame($primaryTicket->id, $duplicateEntry['ticket_id']);
        $this->assertSame(2, $duplicateEntry['occurrences']);
        $this->assertNotNull($duplicateEntry['last_scanned_at']);

        $errorEntry = $response->json('data.errors.data.0');
        $this->assertSame($secondaryTicket->id, $errorEntry['ticket_id']);
        $this->assertSame('invalid', $errorEntry['result']);
        $this->assertSame(1, $errorEntry['occurrences']);
    }

    /**
     * @return array{Ticket, Qr}
     */
    private function createTicketWithQr(Event $event, array $overrides = []): array
    {
        $guest = Guest::query()->create([
            'event_id' => $event->id,
            'full_name' => $overrides['guest_name'] ?? 'Guest '.Str::upper(Str::random(4)),
        ]);

        $ticket = Ticket::query()->create([
            'event_id' => $event->id,
            'guest_id' => $guest->id,
            'type' => 'general',
            'price_cents' => 0,
            'status' => $overrides['status'] ?? 'issued',
            'issued_at' => $overrides['issued_at'] ?? now(),
            'expires_at' => $overrides['expires_at'] ?? null,
        ]);

        $generated = app(QrCodeProvider::class)->generate($ticket);
        $displayCode = $overrides['qr_code'] ?? $generated->displayCode;

        $qr = Qr::query()->create([
            'ticket_id' => $ticket->id,
            'display_code' => $displayCode,
            'payload' => $generated->payload,
            'version' => $overrides['qr_version'] ?? 1,
            'is_active' => $overrides['is_active'] ?? true,
        ]);

        return [$ticket->refresh(), $qr->refresh()];
    }
}
