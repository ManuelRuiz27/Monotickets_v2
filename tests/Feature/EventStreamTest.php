<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Checkpoint;
use App\Models\Event;
use App\Models\Guest;
use App\Models\HostessAssignment;
use App\Models\Qr;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\Venue;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class EventStreamTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenant.id' => null]);
    }

    public function test_stream_returns_aggregated_totals(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
            'checkin_policy' => 'single',
        ]);

        $venue = Venue::factory()->for($event)->create();
        $checkpointAlpha = Checkpoint::factory()->for($event)->for($venue)->create(['name' => 'Alpha']);
        $checkpointBravo = Checkpoint::factory()->for($event)->for($venue)->create(['name' => 'Bravo']);

        [$validTicket] = $this->createTicketWithQr($event, ['guest_name' => 'Valid Guest']);
        [$duplicateTicket] = $this->createTicketWithQr($event, ['guest_name' => 'Duplicate Guest']);
        [$revokedTicket] = $this->createTicketWithQr($event, ['guest_name' => 'Revoked Guest']);
        [$invalidTicket] = $this->createTicketWithQr($event, ['guest_name' => 'Invalid Guest']);

        $scanTimes = [
            CarbonImmutable::parse('2024-07-01T10:00:00Z'),
            CarbonImmutable::parse('2024-07-01T10:01:00Z'),
            CarbonImmutable::parse('2024-07-01T10:02:00Z'),
            CarbonImmutable::parse('2024-07-01T10:03:00Z'),
        ];

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $validTicket->id,
            'guest_id' => $validTicket->guest_id,
            'checkpoint_id' => $checkpointAlpha->id,
            'hostess_user_id' => $organizer->id,
            'result' => 'valid',
            'scanned_at' => $scanTimes[0],
            'device_id' => 'device-1',
            'offline' => false,
            'metadata_json' => [],
        ]);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $duplicateTicket->id,
            'guest_id' => $duplicateTicket->guest_id,
            'checkpoint_id' => $checkpointAlpha->id,
            'hostess_user_id' => $organizer->id,
            'result' => 'duplicate',
            'scanned_at' => $scanTimes[1],
            'device_id' => 'device-1',
            'offline' => false,
            'metadata_json' => [],
        ]);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $revokedTicket->id,
            'guest_id' => $revokedTicket->guest_id,
            'checkpoint_id' => $checkpointBravo->id,
            'hostess_user_id' => $organizer->id,
            'result' => 'revoked',
            'scanned_at' => $scanTimes[2],
            'device_id' => 'device-2',
            'offline' => false,
            'metadata_json' => [],
        ]);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $invalidTicket->id,
            'guest_id' => $invalidTicket->guest_id,
            'checkpoint_id' => null,
            'hostess_user_id' => $organizer->id,
            'result' => 'invalid',
            'scanned_at' => $scanTimes[3],
            'device_id' => 'device-3',
            'offline' => true,
            'metadata_json' => [],
        ]);

        $response = $this->actingAs($organizer, 'api')->get(
            sprintf('/events/%s/stream?interval=2', $event->id),
            ['Accept' => 'text/event-stream']
        );

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/event-stream');

        $body = $response->streamedContent();
        $this->assertNotEmpty($body);
        $this->assertStringContainsString('event: totals', $body);

        $payload = $this->extractSsePayload($body);

        $this->assertSame([
            'valid' => 1,
            'duplicate' => 1,
            'invalid' => 2,
        ], $payload['totals']);

        $checkpointSummaries = collect($payload['checkpoints'])->keyBy(fn ($item) => $item['checkpoint_id']);

        $alphaSummary = $checkpointSummaries->get($checkpointAlpha->id);
        $this->assertNotNull($alphaSummary);
        $this->assertSame([
            'valid' => 1,
            'duplicate' => 1,
            'invalid' => 0,
        ], collect($alphaSummary)->only(['valid', 'duplicate', 'invalid'])->toArray());

        $bravoSummary = $checkpointSummaries->get($checkpointBravo->id);
        $this->assertNotNull($bravoSummary);
        $this->assertSame([
            'valid' => 0,
            'duplicate' => 0,
            'invalid' => 1,
        ], collect($bravoSummary)->only(['valid', 'duplicate', 'invalid'])->toArray());

        $nullCheckpoint = collect($payload['checkpoints'])
            ->firstWhere('checkpoint_id', null);

        $this->assertNotNull($nullCheckpoint);
        $this->assertSame(1, $nullCheckpoint['invalid']);
        $this->assertSame(0, $nullCheckpoint['valid']);
        $this->assertSame(0, $nullCheckpoint['duplicate']);

        $this->assertArrayHasKey('generated_at', $payload);
        $this->assertArrayHasKey('last_change_at', $payload);
    }

    public function test_hostess_requires_active_assignment(): void
    {
        $tenant = Tenant::factory()->create();
        $hostess = $this->createHostess($tenant);

        $event = Event::factory()->for($tenant)->create([
            'status' => 'published',
        ]);

        $response = $this->actingAs($hostess, 'api')->get(
            sprintf('/events/%s/stream', $event->id),
            ['Accept' => 'text/event-stream']
        );

        $response->assertForbidden();

        $this->createHostessAssignment($hostess, $event);

        $authorizedResponse = $this->actingAs($hostess, 'api')->get(
            sprintf('/events/%s/stream', $event->id),
            ['Accept' => 'text/event-stream']
        );

        $authorizedResponse->assertOk();
        $authorizedResponse->assertHeader('Content-Type', 'text/event-stream');
    }

    public function test_stream_updates_after_attendance_creation(): void
    {
        $tenant = Tenant::factory()->create();
        $hostess = $this->createHostess($tenant);

        $event = Event::factory()->for($tenant)->create([
            'status' => 'published',
            'checkin_policy' => 'single',
        ]);

        $this->createHostessAssignment($hostess, $event);

        $initialResponse = $this->actingAs($hostess, 'api')->get(
            sprintf('/events/%s/stream', $event->id),
            ['Accept' => 'text/event-stream']
        );

        $initialResponse->assertOk();
        $initialPayload = $this->extractSsePayload($initialResponse->streamedContent());

        $this->assertSame([
            'valid' => 0,
            'duplicate' => 0,
            'invalid' => 0,
        ], $initialPayload['totals']);

        [$ticket, $qr] = $this->createTicketWithQr($event);

        $scanResponse = $this->actingAs($hostess, 'api')->postJson('/scan', [
            'qr_code' => $qr->code,
            'scanned_at' => CarbonImmutable::parse('2024-07-01T18:00:00Z')->toIso8601String(),
            'device_id' => 'sse-device',
        ]);

        $scanResponse->assertOk();
        $scanResponse->assertJsonPath('data.result', 'valid');

        $updatedResponse = $this->actingAs($hostess, 'api')->get(
            sprintf('/events/%s/stream', $event->id),
            ['Accept' => 'text/event-stream']
        );

        $updatedResponse->assertOk();
        $updatedPayload = $this->extractSsePayload($updatedResponse->streamedContent());

        $this->assertSame(1, $updatedPayload['totals']['valid']);
        $this->assertSame(0, $updatedPayload['totals']['duplicate']);
        $this->assertSame(0, $updatedPayload['totals']['invalid']);
    }

    private function createHostessAssignment(User $hostess, Event $event, ?Venue $venue = null, ?Checkpoint $checkpoint = null): void
    {
        HostessAssignment::query()->create([
            'tenant_id' => $event->tenant_id,
            'hostess_user_id' => $hostess->id,
            'event_id' => $event->id,
            'venue_id' => $venue?->id,
            'checkpoint_id' => $checkpoint?->id,
            'starts_at' => CarbonImmutable::now()->subHour(),
            'ends_at' => CarbonImmutable::now()->addHour(),
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractSsePayload(string $body): array
    {
        preg_match('/data: (.+)/', $body, $matches);
        $this->assertArrayHasKey(1, $matches);

        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
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

        $qr = Qr::query()->create([
            'ticket_id' => $ticket->id,
            'code' => $overrides['qr_code'] ?? sprintf('QR-%s', Str::upper(Str::random(8))),
            'version' => $overrides['qr_version'] ?? 1,
            'is_active' => $overrides['is_active'] ?? true,
        ]);

        return [$ticket->refresh(), $qr->refresh()];
    }
}
