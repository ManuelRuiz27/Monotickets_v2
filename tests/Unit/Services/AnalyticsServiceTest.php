<?php

namespace Tests\Unit\Services;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Ticket;
use App\Services\Analytics\AnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_metrics_are_calculated_with_time_windows(): void
    {
        $service = new AnalyticsService();

        $event = Event::factory()->create([
            'timezone' => 'America/Bogota',
            'capacity' => 10,
        ]);

        $guestOne = Guest::query()->create([
            'event_id' => $event->id,
            'full_name' => 'Guest One',
            'email' => 'guest1@example.com',
            'rsvp_status' => 'confirmed',
        ]);

        $guestTwo = Guest::query()->create([
            'event_id' => $event->id,
            'full_name' => 'Guest Two',
            'email' => 'guest2@example.com',
            'rsvp_status' => 'confirmed',
        ]);

        $guestThree = Guest::query()->create([
            'event_id' => $event->id,
            'full_name' => 'Guest Three',
            'email' => 'guest3@example.com',
            'rsvp_status' => 'invited',
        ]);

        $guestFour = Guest::query()->create([
            'event_id' => $event->id,
            'full_name' => 'Guest Four',
            'email' => 'guest4@example.com',
            'rsvp_status' => 'confirmed',
        ]);

        $guestFour->delete();

        $ticketOne = Ticket::query()->create([
            'event_id' => $event->id,
            'guest_id' => $guestOne->id,
            'type' => 'general',
            'price_cents' => 0,
            'status' => 'issued',
            'issued_at' => CarbonImmutable::parse('2023-12-31 18:00:00', 'UTC'),
        ]);

        $ticketTwo = Ticket::query()->create([
            'event_id' => $event->id,
            'guest_id' => $guestTwo->id,
            'type' => 'general',
            'price_cents' => 0,
            'status' => 'issued',
            'issued_at' => CarbonImmutable::parse('2023-12-31 18:00:00', 'UTC'),
        ]);

        $ticketThree = Ticket::query()->create([
            'event_id' => $event->id,
            'guest_id' => $guestThree->id,
            'type' => 'general',
            'price_cents' => 0,
            'status' => 'issued',
            'issued_at' => CarbonImmutable::parse('2023-12-31 18:00:00', 'UTC'),
        ]);

        $insideWindow = CarbonImmutable::parse('2024-01-01 10:15:30', $event->timezone);
        $beforeWindow = CarbonImmutable::parse('2024-01-01 10:14:59', $event->timezone);
        $afterWindow = CarbonImmutable::parse('2024-01-01 10:16:00', $event->timezone);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $ticketOne->id,
            'guest_id' => $guestOne->id,
            'result' => 'valid',
            'scanned_at' => $insideWindow,
        ]);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $ticketOne->id,
            'guest_id' => $guestOne->id,
            'result' => 'duplicate',
            'scanned_at' => $insideWindow->addSeconds(10),
        ]);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $ticketOne->id,
            'guest_id' => $guestOne->id,
            'result' => 'valid',
            'scanned_at' => $afterWindow,
        ]);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $ticketTwo->id,
            'guest_id' => $guestTwo->id,
            'result' => 'valid',
            'scanned_at' => $insideWindow->addSeconds(5),
        ]);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $ticketTwo->id,
            'guest_id' => $guestTwo->id,
            'result' => 'valid',
            'scanned_at' => $beforeWindow,
        ]);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $ticketThree->id,
            'guest_id' => $guestThree->id,
            'result' => 'valid',
            'scanned_at' => $beforeWindow->subMinute(),
        ]);

        $metrics = $service->overview($event->id, '2024-01-01T10:15:00', '2024-01-01T10:15:00');

        $this->assertSame([
            'invited' => 3,
            'confirmed' => 2,
            'attendances' => 2,
            'duplicates' => 1,
            'unique_attendees' => 2,
            'occupancy_rate' => 0.2,
        ], $metrics);
    }
}
