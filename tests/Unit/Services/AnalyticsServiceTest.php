<?php

namespace Tests\Unit\Services;

use App\Models\ActivityMetric;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestList;
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

    public function test_attendance_by_hour_respects_event_timezone(): void
    {
        $service = new AnalyticsService();

        $event = Event::factory()->create([
            'timezone' => 'America/Bogota',
        ]);

        ActivityMetric::query()->create([
            'event_id' => $event->id,
            'date_hour' => CarbonImmutable::parse('2024-01-01T14:00:00Z'),
            'invites_sent' => 1,
            'rsvp_confirmed' => 1,
            'scans_valid' => 5,
            'scans_duplicate' => 0,
            'unique_guests_in' => 5,
        ]);

        ActivityMetric::query()->create([
            'event_id' => $event->id,
            'date_hour' => CarbonImmutable::parse('2024-01-01T15:00:00Z'),
            'invites_sent' => 2,
            'rsvp_confirmed' => 1,
            'scans_valid' => 3,
            'scans_duplicate' => 1,
            'unique_guests_in' => 3,
        ]);

        ActivityMetric::query()->create([
            'event_id' => $event->id,
            'date_hour' => CarbonImmutable::parse('2024-01-01T16:00:00Z'),
            'invites_sent' => 4,
            'rsvp_confirmed' => 2,
            'scans_valid' => 6,
            'scans_duplicate' => 0,
            'unique_guests_in' => 6,
        ]);

        $results = $service->attendanceByHour($event->id, '2024-01-01T10:00:00', '2024-01-01T10:00:00');

        $this->assertCount(1, $results);
        $this->assertSame('2024-01-01T15:00:00+00:00', $results[0]['date_hour']);
        $this->assertSame(2, $results[0]['invites_sent']);
        $this->assertSame(1, $results[0]['rsvp_confirmed']);
        $this->assertSame(3, $results[0]['scans_valid']);
        $this->assertSame(1, $results[0]['scans_duplicate']);
        $this->assertSame(3, $results[0]['unique_guests_in']);
    }

    public function test_rsvp_funnel_is_scoped_to_event(): void
    {
        $service = new AnalyticsService();

        $event = Event::factory()->create();
        $otherEvent = Event::factory()->create();

        Guest::query()->create([
            'event_id' => $event->id,
            'full_name' => 'Invited Guest',
            'rsvp_status' => 'invited',
        ]);

        Guest::query()->create([
            'event_id' => $event->id,
            'full_name' => 'Confirmed Guest',
            'rsvp_status' => 'confirmed',
        ]);

        Guest::query()->create([
            'event_id' => $event->id,
            'full_name' => 'Declined Guest',
            'rsvp_status' => 'declined',
        ]);

        Guest::query()->create([
            'event_id' => $otherEvent->id,
            'full_name' => 'Other Tenant Invited',
            'rsvp_status' => 'invited',
        ]);

        $totals = $service->rsvpFunnel($event->id);

        $this->assertSame([
            'invited' => 1,
            'confirmed' => 1,
            'declined' => 1,
        ], $totals);
    }

    public function test_guests_by_list_aggregates_within_event_scope(): void
    {
        $service = new AnalyticsService();

        $event = Event::factory()->create();
        $otherEvent = Event::factory()->create();

        $alphaList = GuestList::query()->create([
            'event_id' => $event->id,
            'name' => 'Alpha',
        ]);

        $betaList = GuestList::query()->create([
            'event_id' => $event->id,
            'name' => 'Beta',
        ]);

        $externalList = GuestList::query()->create([
            'event_id' => $otherEvent->id,
            'name' => 'External',
        ]);

        Guest::query()->create([
            'event_id' => $event->id,
            'guest_list_id' => $alphaList->id,
            'full_name' => 'Alpha One',
        ]);

        Guest::query()->create([
            'event_id' => $event->id,
            'guest_list_id' => $betaList->id,
            'full_name' => 'Beta One',
        ]);

        Guest::query()->create([
            'event_id' => $event->id,
            'guest_list_id' => $betaList->id,
            'full_name' => 'Beta Two',
        ]);

        Guest::query()->create([
            'event_id' => $otherEvent->id,
            'guest_list_id' => $externalList->id,
            'full_name' => 'External Guest',
        ]);

        $result = $service->guestsByList($event->id);

        $this->assertSame(3, $result['total']);
        $this->assertCount(2, $result['lists']);
        $this->assertSame('Alpha', $result['lists'][0]['guest_list_name']);
        $this->assertSame(1, $result['lists'][0]['guests']);
        $this->assertSame('Beta', $result['lists'][1]['guest_list_name']);
        $this->assertSame(2, $result['lists'][1]['guests']);
    }
}
