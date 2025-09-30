<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\Guest;
use App\Models\HostessAssignment;
use App\Models\Qr;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Services\Qr\QrCodeProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class EventAttendanceControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenant.id' => null]);
    }

    public function test_attendances_since_returns_feed_for_hostess_with_assignment(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $hostess = $this->createHostess($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
        ]);

        $this->createAssignment($event, $hostess);

        [$firstTicket] = $this->createTicketWithQr($event, ['guest_name' => 'Guest A']);
        [$secondTicket] = $this->createTicketWithQr($event, ['guest_name' => 'Guest B']);
        [$thirdTicket] = $this->createTicketWithQr($event, ['guest_name' => 'Guest C']);

        $firstAttendance = Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $firstTicket->id,
            'guest_id' => $firstTicket->guest_id,
            'checkpoint_id' => null,
            'hostess_user_id' => $hostess->id,
            'result' => 'valid',
            'scanned_at' => CarbonImmutable::parse('2024-07-01T10:05:00Z'),
            'device_id' => 'device-a',
            'offline' => false,
            'metadata_json' => ['reason' => 'accepted'],
        ]);

        $secondAttendance = Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $secondTicket->id,
            'guest_id' => $secondTicket->guest_id,
            'checkpoint_id' => null,
            'hostess_user_id' => $hostess->id,
            'result' => 'duplicate',
            'scanned_at' => CarbonImmutable::parse('2024-07-01T10:10:00Z'),
            'device_id' => 'device-b',
            'offline' => true,
            'metadata_json' => ['reason' => 'duplicate'],
        ]);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $thirdTicket->id,
            'guest_id' => $thirdTicket->guest_id,
            'checkpoint_id' => null,
            'hostess_user_id' => $hostess->id,
            'result' => 'valid',
            'scanned_at' => CarbonImmutable::parse('2024-07-01T10:15:00Z'),
            'device_id' => 'device-c',
            'offline' => false,
            'metadata_json' => ['reason' => 'accepted'],
        ]);

        $cursor = CarbonImmutable::parse('2024-07-01T10:00:00Z')->toIso8601String();

        $response = $this->actingAs($hostess, 'api')->getJson(sprintf(
            '/events/%s/attendances/since?cursor=%s&limit=2',
            $event->id,
            urlencode($cursor)
        ));

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.id', $firstAttendance->id);
        $response->assertJsonPath('data.0.ticket_id', $firstTicket->id);
        $response->assertJsonPath('data.0.result', 'valid');
        $response->assertJsonPath('data.0.offline', false);
        $response->assertJsonPath('data.1.id', $secondAttendance->id);
        $response->assertJsonPath('data.1.offline', true);
        $response->assertJsonPath('meta.next_cursor', $secondAttendance->scanned_at->toISOString());
    }

    public function test_attendances_since_requires_active_assignment_for_hostess(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $hostess = $this->createHostess($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
        ]);

        $response = $this->actingAs($hostess, 'api')->getJson(sprintf(
            '/events/%s/attendances/since',
            $event->id
        ));

        $response->assertForbidden();
    }

    public function test_ticket_state_returns_ticket_and_last_attendance(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $hostess = $this->createHostess($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
        ]);

        $this->createAssignment($event, $hostess);

        [$ticket] = $this->createTicketWithQr($event, ['guest_name' => 'Guest State']);

        $attendance = Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $ticket->id,
            'guest_id' => $ticket->guest_id,
            'checkpoint_id' => null,
            'hostess_user_id' => $hostess->id,
            'result' => 'valid',
            'scanned_at' => CarbonImmutable::parse('2024-07-01T11:00:00Z'),
            'device_id' => 'state-device',
            'offline' => false,
            'metadata_json' => ['reason' => 'accepted'],
        ]);

        $ticket->forceFill(['status' => 'used'])->save();

        $response = $this->actingAs($hostess, 'api')->getJson(sprintf(
            '/events/%s/tickets/%s/state',
            $event->id,
            $ticket->id
        ));

        $response->assertOk();
        $response->assertJsonPath('data.ticket.id', $ticket->id);
        $response->assertJsonPath('data.ticket.status', 'used');
        $response->assertJsonPath('data.last_attendance.id', $attendance->id);
        $response->assertJsonPath('data.last_attendance.result', 'valid');
    }

    public function test_ticket_state_allows_organizer_without_assignment(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
        ]);

        [$ticket] = $this->createTicketWithQr($event);

        $response = $this->actingAs($organizer, 'api')->getJson(sprintf(
            '/events/%s/tickets/%s/state',
            $event->id,
            $ticket->id
        ));

        $response->assertOk();
        $response->assertJsonPath('data.ticket.id', $ticket->id);
        $response->assertNull($response->json('data.last_attendance'));
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

    private function createAssignment(Event $event, $hostess): HostessAssignment
    {
        return HostessAssignment::query()->create([
            'tenant_id' => $event->tenant_id,
            'hostess_user_id' => $hostess->id,
            'event_id' => $event->id,
            'venue_id' => null,
            'checkpoint_id' => null,
            'starts_at' => now()->subHour(),
            'ends_at' => null,
            'is_active' => true,
        ]);
    }
}

