<?php

namespace Tests\Unit\Jobs;

use App\Jobs\AggregateActivityHourly;
use App\Models\ActivityMetric;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AggregateActivityHourlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_consolidates_multiple_attendances_into_hourly_buckets(): void
    {
        $event = Event::factory()->create(['timezone' => 'UTC']);

        $firstGuest = Guest::query()->create([
            'event_id' => $event->id,
            'full_name' => 'Guest One',
            'rsvp_status' => 'confirmed',
            'rsvp_at' => CarbonImmutable::parse('2024-01-01T10:30:00Z'),
        ]);
        Guest::query()->whereKey($firstGuest->id)->update([
            'created_at' => CarbonImmutable::parse('2024-01-01T10:05:00Z'),
        ]);

        $secondGuest = Guest::query()->create([
            'event_id' => $event->id,
            'full_name' => 'Guest Two',
            'rsvp_status' => 'confirmed',
            'rsvp_at' => CarbonImmutable::parse('2024-01-01T11:10:00Z'),
        ]);
        Guest::query()->whereKey($secondGuest->id)->update([
            'created_at' => CarbonImmutable::parse('2024-01-01T11:05:00Z'),
        ]);

        $firstTicket = Ticket::query()->create([
            'event_id' => $event->id,
            'guest_id' => $firstGuest->id,
            'type' => 'general',
            'price_cents' => 0,
            'status' => 'issued',
            'issued_at' => CarbonImmutable::parse('2023-12-31T12:00:00Z'),
        ]);

        $secondTicket = Ticket::query()->create([
            'event_id' => $event->id,
            'guest_id' => $secondGuest->id,
            'type' => 'general',
            'price_cents' => 0,
            'status' => 'issued',
            'issued_at' => CarbonImmutable::parse('2023-12-31T12:00:00Z'),
        ]);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $firstTicket->id,
            'guest_id' => $firstGuest->id,
            'result' => 'valid',
            'scanned_at' => CarbonImmutable::parse('2024-01-01T10:15:00Z'),
        ]);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $firstTicket->id,
            'guest_id' => $firstGuest->id,
            'result' => 'duplicate',
            'scanned_at' => CarbonImmutable::parse('2024-01-01T10:25:00Z'),
        ]);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $secondTicket->id,
            'guest_id' => $secondGuest->id,
            'result' => 'valid',
            'scanned_at' => CarbonImmutable::parse('2024-01-01T11:20:00Z'),
        ]);

        $otherEvent = Event::factory()->create();
        $otherGuest = Guest::query()->create([
            'event_id' => $otherEvent->id,
            'full_name' => 'Other Guest',
            'rsvp_status' => 'confirmed',
        ]);
        $otherTicket = Ticket::query()->create([
            'event_id' => $otherEvent->id,
            'guest_id' => $otherGuest->id,
            'type' => 'general',
            'price_cents' => 0,
            'status' => 'issued',
            'issued_at' => CarbonImmutable::parse('2023-12-31T12:00:00Z'),
        ]);
        Attendance::query()->create([
            'event_id' => $otherEvent->id,
            'ticket_id' => $otherTicket->id,
            'guest_id' => $otherGuest->id,
            'result' => 'valid',
            'scanned_at' => CarbonImmutable::parse('2024-01-01T10:00:00Z'),
        ]);

        (new AggregateActivityHourly())->handle();

        $metrics = ActivityMetric::query()
            ->where('event_id', $event->id)
            ->orderBy('date_hour')
            ->get();

        $this->assertCount(2, $metrics);

        $firstHour = $metrics->first();
        $this->assertTrue($firstHour->date_hour->equalTo(CarbonImmutable::parse('2024-01-01T10:00:00Z')));
        $this->assertSame(1, $firstHour->invites_sent);
        $this->assertSame(1, $firstHour->rsvp_confirmed);
        $this->assertSame(1, $firstHour->scans_valid);
        $this->assertSame(1, $firstHour->scans_duplicate);
        $this->assertSame(1, $firstHour->unique_guests_in);

        $secondHour = $metrics->last();
        $this->assertTrue($secondHour->date_hour->equalTo(CarbonImmutable::parse('2024-01-01T11:00:00Z')));
        $this->assertSame(1, $secondHour->invites_sent);
        $this->assertSame(1, $secondHour->rsvp_confirmed);
        $this->assertSame(1, $secondHour->scans_valid);
        $this->assertSame(0, $secondHour->scans_duplicate);
        $this->assertSame(1, $secondHour->unique_guests_in);

        $this->assertSame(1, ActivityMetric::query()->where('event_id', $otherEvent->id)->count());
    }
}
