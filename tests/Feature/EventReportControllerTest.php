<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Checkpoint;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\Venue;
use App\Services\Analytics\AnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class EventReportControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenant.id' => null]);
    }

    public function test_attendance_csv_returns_expected_headers_and_rows(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
        ]);

        $venue = Venue::query()->create([
            'event_id' => $event->id,
            'name' => 'Main Hall',
            'address' => '123 Street',
            'lat' => 0,
            'lng' => 0,
        ]);

        $checkpoint = Checkpoint::query()->create([
            'event_id' => $event->id,
            'venue_id' => $venue->id,
            'name' => 'Gate A',
        ]);

        $hostess = $this->createHostess($tenant);
        $hostess->forceFill(['name' => 'Harper Host'])->save();

        $guestOne = Guest::query()->create([
            'event_id' => $event->id,
            'full_name' => 'Guest One',
        ]);

        $guestTwo = Guest::query()->create([
            'event_id' => $event->id,
            'full_name' => 'Guest Two',
        ]);

        $ticketOne = Ticket::query()->create([
            'event_id' => $event->id,
            'guest_id' => $guestOne->id,
            'type' => 'general',
            'price_cents' => 0,
            'status' => 'issued',
            'issued_at' => CarbonImmutable::parse('2024-01-01T09:00:00Z'),
        ]);

        $ticketTwo = Ticket::query()->create([
            'event_id' => $event->id,
            'guest_id' => $guestTwo->id,
            'type' => 'general',
            'price_cents' => 0,
            'status' => 'issued',
            'issued_at' => CarbonImmutable::parse('2024-01-01T09:30:00Z'),
        ]);

        $firstAttendance = Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $ticketOne->id,
            'guest_id' => $guestOne->id,
            'checkpoint_id' => $checkpoint->id,
            'hostess_user_id' => $hostess->id,
            'result' => 'valid',
            'scanned_at' => CarbonImmutable::parse('2024-01-01T10:00:00Z'),
        ]);

        $secondAttendance = Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $ticketTwo->id,
            'guest_id' => $guestTwo->id,
            'checkpoint_id' => null,
            'hostess_user_id' => null,
            'result' => 'duplicate',
            'scanned_at' => CarbonImmutable::parse('2024-01-01T11:00:00Z'),
        ]);

        $response = $this->actingAs($organizer, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->get(sprintf('/events/%s/reports/attendance.csv', $event->id));

        $response->assertOk();
        $this->assertSame('text/csv', $response->headers->get('content-type'));

        $content = $response->streamedContent();
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', trim($content))));

        $this->assertSame(['timestamp', 'checkpoint', 'ticket', 'guest', 'hostess', 'result'], str_getcsv($lines[0]));

        $firstRow = str_getcsv($lines[1]);
        $this->assertSame($firstAttendance->scanned_at->toISOString(), $firstRow[0]);
        $this->assertSame('Gate A', $firstRow[1]);
        $this->assertSame($ticketOne->id, $firstRow[2]);
        $this->assertSame('Guest One', $firstRow[3]);
        $this->assertSame('Harper Host', $firstRow[4]);
        $this->assertSame('valid', $firstRow[5]);

        $secondRow = str_getcsv($lines[2]);
        $this->assertSame($secondAttendance->scanned_at->toISOString(), $secondRow[0]);
        $this->assertSame('', $secondRow[1]);
        $this->assertSame($ticketTwo->id, $secondRow[2]);
        $this->assertSame('Guest Two', $secondRow[3]);
        $this->assertSame('', $secondRow[4]);
        $this->assertSame('duplicate', $secondRow[5]);
    }

    public function test_summary_pdf_generates_valid_document(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
            'name' => 'Launch Event',
        ]);

        $venue = Venue::query()->create([
            'event_id' => $event->id,
            'name' => 'Conference Center',
            'address' => '456 Avenue',
            'lat' => 0,
            'lng' => 0,
        ]);

        $checkpoint = Checkpoint::query()->create([
            'event_id' => $event->id,
            'venue_id' => $venue->id,
            'name' => 'Checkpoint 1',
        ]);

        $now = CarbonImmutable::parse('2024-02-01T12:00:00Z');
        CarbonImmutable::setTestNow($now);

        $expectedFrom = $now->subHours(48);
        $expectedTo = $now;

        $analytics = $this->createMock(AnalyticsService::class);
        $analytics->expects($this->once())
            ->method('overview')
            ->with(
                $event->id,
                $this->callback(fn ($value) => $value instanceof CarbonImmutable && $value->equalTo($expectedFrom)),
                $this->callback(fn ($value) => $value instanceof CarbonImmutable && $value->equalTo($expectedTo))
            )
            ->willReturn([
                'invited' => 10,
                'confirmed' => 5,
                'attendances' => 4,
                'duplicates' => 1,
                'unique_attendees' => 4,
                'occupancy_rate' => 0.4,
            ]);

        $analytics->expects($this->once())
            ->method('attendanceByHour')
            ->with(
                $event->id,
                $this->callback(fn ($value) => $value instanceof CarbonImmutable && $value->equalTo($expectedFrom)),
                $this->callback(fn ($value) => $value instanceof CarbonImmutable && $value->equalTo($expectedTo))
            )
            ->willReturn([
                [
                    'date_hour' => '2024-02-01T10:00:00+00:00',
                    'invites_sent' => 2,
                    'rsvp_confirmed' => 1,
                    'scans_valid' => 3,
                    'scans_duplicate' => 0,
                    'unique_guests_in' => 3,
                ],
            ]);

        $analytics->expects($this->once())
            ->method('checkpointTotals')
            ->with(
                $event->id,
                $this->callback(fn ($value) => $value instanceof CarbonImmutable && $value->equalTo($expectedFrom)),
                $this->callback(fn ($value) => $value instanceof CarbonImmutable && $value->equalTo($expectedTo))
            )
            ->willReturn([
                'totals' => [
                    'valid' => 3,
                    'duplicate' => 0,
                    'invalid' => 1,
                ],
                'checkpoints' => [
                    [
                        'checkpoint_id' => $checkpoint->id,
                        'valid' => 3,
                        'duplicate' => 0,
                        'invalid' => 1,
                    ],
                ],
            ]);

        $analytics->expects($this->once())
            ->method('guestsByList')
            ->with($event->id)
            ->willReturn([
                'total' => 6,
                'lists' => [
                    [
                        'guest_list_id' => null,
                        'guest_list_name' => null,
                        'guests' => 6,
                    ],
                ],
            ]);

        $analytics->expects($this->once())
            ->method('rsvpFunnel')
            ->with($event->id)
            ->willReturn([
                'invited' => 10,
                'confirmed' => 5,
                'declined' => 1,
            ]);

        app()->instance(AnalyticsService::class, $analytics);

        $response = $this->actingAs($organizer, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->get(sprintf('/events/%s/reports/summary.pdf', $event->id));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('inline; filename="summary.pdf"', $response->headers->get('content-disposition'));

        $content = $response->getContent();
        $this->assertNotEmpty($content);
        $this->assertStringStartsWith('%PDF', $content);

        CarbonImmutable::setTestNow();
    }
}
