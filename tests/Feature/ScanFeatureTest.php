<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Checkpoint;
use App\Models\Event;
use App\Models\HostessAssignment;
use App\Models\Guest;
use App\Models\Qr;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\Venue;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class ScanFeatureTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenant.id' => null]);
    }

    public function test_store_records_valid_scan_and_marks_ticket_used(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $hostess = $this->createHostess($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
            'checkin_policy' => 'single',
        ]);

        $this->assignHostessToEvent($hostess, $event);

        [$ticket, $qr] = $this->createTicketWithQr($event);
        $scanTime = CarbonImmutable::parse('2024-07-01T10:15:00Z');

        $response = $this->actingAs($hostess, 'api')->postJson('/scan', [
            'qr_code' => $qr->code,
            'scanned_at' => $scanTime->toIso8601String(),
            'device_id' => 'device-001',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.result', 'valid');
        $response->assertJsonPath('data.ticket.status', 'used');
        $response->assertJsonPath('data.attendance.result', 'valid');
        $response->assertJsonPath('data.attendance.offline', false);

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'status' => 'used',
        ]);

        $this->assertDatabaseHas('attendances', [
            'ticket_id' => $ticket->id,
            'result' => 'valid',
        ]);

        $auditLog = AuditLog::query()
            ->where('entity', 'ticket')
            ->where('entity_id', $ticket->id)
            ->latest('occurred_at')
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertSame('scan_valid', $auditLog->action);
    }

    public function test_store_returns_duplicate_when_ticket_already_scanned_in_single_policy(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $hostess = $this->createHostess($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
            'checkin_policy' => 'single',
        ]);

        $this->assignHostessToEvent($hostess, $event);

        [$ticket, $qr] = $this->createTicketWithQr($event);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $ticket->id,
            'guest_id' => $ticket->guest_id,
            'checkpoint_id' => null,
            'hostess_user_id' => $hostess->id,
            'result' => 'valid',
            'scanned_at' => CarbonImmutable::parse('2024-07-01T10:00:00Z'),
            'device_id' => 'device-initial',
            'offline' => false,
            'metadata_json' => ['reason' => 'accepted'],
        ]);

        $ticket->forceFill(['status' => 'used'])->save();

        $response = $this->actingAs($hostess, 'api')->postJson('/scan', [
            'qr_code' => $qr->code,
            'scanned_at' => CarbonImmutable::parse('2024-07-01T10:30:00Z')->toIso8601String(),
            'device_id' => 'device-002',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.result', 'duplicate');
        $response->assertJsonPath('data.attendance.result', 'duplicate');

        $this->assertDatabaseHas('attendances', [
            'ticket_id' => $ticket->id,
            'result' => 'duplicate',
        ]);

        $auditLog = AuditLog::query()
            ->where('entity', 'ticket')
            ->where('entity_id', $ticket->id)
            ->latest('occurred_at')
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertSame('scan_duplicate', $auditLog->action);
    }

    public function test_store_allows_multiple_valid_scans_in_multiple_policy(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $hostess = $this->createHostess($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
            'checkin_policy' => 'multiple',
        ]);

        $this->assignHostessToEvent($hostess, $event);

        [$ticket, $qr] = $this->createTicketWithQr($event);

        $firstResponse = $this->actingAs($hostess, 'api')->postJson('/scan', [
            'qr_code' => $qr->code,
            'scanned_at' => CarbonImmutable::parse('2024-07-01T09:00:00Z')->toIso8601String(),
            'device_id' => 'device-multi-1',
        ]);

        $firstResponse->assertOk();
        $firstResponse->assertJsonPath('data.result', 'valid');
        $firstResponse->assertJsonPath('data.attendance.result', 'valid');

        $secondResponse = $this->actingAs($hostess, 'api')->postJson('/scan', [
            'qr_code' => $qr->code,
            'scanned_at' => CarbonImmutable::parse('2024-07-01T09:05:00Z')->toIso8601String(),
            'device_id' => 'device-multi-2',
        ]);

        $secondResponse->assertOk();
        $secondResponse->assertJsonPath('data.result', 'valid');
        $secondResponse->assertJsonPath('data.attendance.result', 'valid');

        $this->assertSame(2, Attendance::query()->where('ticket_id', $ticket->id)->where('result', 'valid')->count());
    }

    public function test_store_returns_revoked_for_revoked_ticket(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $hostess = $this->createHostess($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
            'checkin_policy' => 'single',
        ]);

        $this->assignHostessToEvent($hostess, $event);

        [$ticket, $qr] = $this->createTicketWithQr($event, [
            'status' => 'revoked',
        ]);

        $response = $this->actingAs($hostess, 'api')->postJson('/scan', [
            'qr_code' => $qr->code,
            'scanned_at' => CarbonImmutable::parse('2024-07-01T11:00:00Z')->toIso8601String(),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.result', 'revoked');
        $response->assertJsonPath('data.attendance.result', 'revoked');

        $this->assertDatabaseHas('attendances', [
            'ticket_id' => $ticket->id,
            'result' => 'revoked',
        ]);

        $auditLog = AuditLog::query()
            ->where('entity', 'ticket')
            ->where('entity_id', $ticket->id)
            ->latest('occurred_at')
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertSame('scan_revoked', $auditLog->action);
    }

    public function test_scan_returns_revoked_after_ticket_status_update(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $hostess = $this->createHostess($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
            'checkin_policy' => 'single',
        ]);

        $this->assignHostessToEvent($hostess, $event);

        [$ticket, $qr] = $this->createTicketWithQr($event);

        $updateResponse = $this->actingAs($organizer, 'api')->patchJson(
            sprintf('/tickets/%s', $ticket->id),
            ['status' => 'revoked']
        );

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.status', 'revoked');

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'status' => 'revoked',
        ]);

        $scanResponse = $this->actingAs($hostess, 'api')->postJson('/scan', [
            'qr_code' => $qr->code,
            'scanned_at' => CarbonImmutable::parse('2024-07-01T12:00:00Z')->toIso8601String(),
        ]);

        $scanResponse->assertOk();
        $scanResponse->assertJsonPath('data.result', 'revoked');
        $scanResponse->assertJsonPath('data.attendance.result', 'revoked');

        $this->assertDatabaseHas('attendances', [
            'ticket_id' => $ticket->id,
            'result' => 'revoked',
        ]);
    }

    public function test_store_returns_expired_when_ticket_past_expiration(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $hostess = $this->createHostess($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
            'checkin_policy' => 'single',
        ]);

        $this->assignHostessToEvent($hostess, $event);

        $expiresAt = CarbonImmutable::parse('2024-07-01T09:00:00Z');
        [$ticket, $qr] = $this->createTicketWithQr($event, [
            'expires_at' => $expiresAt,
        ]);

        $response = $this->actingAs($hostess, 'api')->postJson('/scan', [
            'qr_code' => $qr->code,
            'scanned_at' => CarbonImmutable::parse('2024-07-01T10:00:00Z')->toIso8601String(),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.result', 'expired');
        $response->assertJsonPath('data.attendance.result', 'expired');

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'status' => 'expired',
        ]);

        $auditLog = AuditLog::query()
            ->where('entity', 'ticket')
            ->where('entity_id', $ticket->id)
            ->latest('occurred_at')
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertSame('scan_expired', $auditLog->action);
    }

    public function test_store_returns_invalid_when_qr_not_found(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $hostess = $this->createHostess($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
            'checkin_policy' => 'single',
        ]);

        $this->assignHostessToEvent($hostess, $event);

        $this->createTicketWithQr($event);

        $response = $this->actingAs($hostess, 'api')->postJson('/scan', [
            'qr_code' => 'UNKNOWN-CODE',
            'scanned_at' => CarbonImmutable::parse('2024-07-01T10:00:00Z')->toIso8601String(),
            'device_id' => 'device-unknown',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.result', 'invalid');
        $response->assertJsonPath('data.reason', 'qr_not_found');
        $this->assertNull($response->json('data.ticket'));
        $this->assertNull($response->json('data.attendance'));
    }

    public function test_store_returns_invalid_when_qr_inactive(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $hostess = $this->createHostess($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
            'checkin_policy' => 'single',
        ]);

        $this->assignHostessToEvent($hostess, $event);

        [$ticket, $qr] = $this->createTicketWithQr($event, [
            'is_active' => false,
        ]);

        $response = $this->actingAs($hostess, 'api')->postJson('/scan', [
            'qr_code' => $qr->code,
            'scanned_at' => CarbonImmutable::parse('2024-07-01T12:00:00Z')->toIso8601String(),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.result', 'invalid');
        $response->assertJsonPath('data.attendance.result', 'invalid');
        $response->assertJsonPath('data.reason', 'qr_inactive');

        $auditLog = AuditLog::query()
            ->where('entity', 'ticket')
            ->where('entity_id', $ticket->id)
            ->latest('occurred_at')
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertSame('scan_invalid', $auditLog->action);
    }

    public function test_store_returns_invalid_when_checkpoint_not_from_event(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $hostess = $this->createHostess($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
            'checkin_policy' => 'single',
        ]);

        $this->assignHostessToEvent($hostess, $event);

        $otherEvent = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
            'checkin_policy' => 'single',
        ]);

        $venue = Venue::factory()->create(['event_id' => $otherEvent->id]);
        $checkpoint = Checkpoint::factory()->create([
            'event_id' => $otherEvent->id,
            'venue_id' => $venue->id,
        ]);

        [$ticket, $qr] = $this->createTicketWithQr($event);

        $response = $this->actingAs($hostess, 'api')->postJson('/scan', [
            'qr_code' => $qr->code,
            'scanned_at' => CarbonImmutable::parse('2024-07-01T13:00:00Z')->toIso8601String(),
            'checkpoint_id' => $checkpoint->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.result', 'invalid');
        $response->assertJsonPath('data.reason', 'checkpoint_invalid');
        $response->assertJsonPath('data.attendance.result', 'invalid');

        $this->assertDatabaseHas('attendances', [
            'ticket_id' => $ticket->id,
            'result' => 'invalid',
        ]);
    }

    public function test_hostess_without_assignment_cannot_scan(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $hostess = $this->createHostess($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
            'checkin_policy' => 'single',
        ]);

        [$ticket, $qr] = $this->createTicketWithQr($event);
        $scanTime = CarbonImmutable::parse('2024-07-01T14:00:00Z');

        $unauthorized = $this->actingAs($hostess, 'api')->postJson('/scan', [
            'qr_code' => $qr->code,
            'scanned_at' => $scanTime->toIso8601String(),
            'device_id' => 'assignment-check',
        ]);

        $unauthorized->assertForbidden();
        $this->assertSame(0, Attendance::query()->count());

        $this->assignHostessToEvent($hostess, $event);

        $authorized = $this->actingAs($hostess, 'api')->postJson('/scan', [
            'qr_code' => $qr->code,
            'scanned_at' => $scanTime->addMinute()->toIso8601String(),
            'device_id' => 'assignment-check',
        ]);

        $authorized->assertOk();
        $authorized->assertJsonPath('data.result', 'valid');
    }

    public function test_hostess_assignment_respects_venue_scope(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $hostess = $this->createHostess($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
            'checkin_policy' => 'single',
        ]);

        $venueAlpha = Venue::factory()->for($event)->create(['name' => 'Alpha Venue']);
        $venueBravo = Venue::factory()->for($event)->create(['name' => 'Bravo Venue']);
        $checkpointAlpha = Checkpoint::factory()->for($event)->for($venueAlpha)->create();
        $checkpointBravo = Checkpoint::factory()->for($event)->for($venueBravo)->create();

        [$ticket, $qr] = $this->createTicketWithQr($event);

        $this->assignHostessToEvent($hostess, $event, $venueAlpha);

        $forbidden = $this->actingAs($hostess, 'api')->postJson('/scan', [
            'qr_code' => $qr->code,
            'scanned_at' => CarbonImmutable::parse('2024-07-01T15:00:00Z')->toIso8601String(),
            'device_id' => 'venue-check',
            'checkpoint_id' => $checkpointBravo->id,
        ]);

        $forbidden->assertForbidden();
        $this->assertSame(0, Attendance::query()->count());

        $this->assignHostessToEvent($hostess, $event, $venueBravo);

        $allowed = $this->actingAs($hostess, 'api')->postJson('/scan', [
            'qr_code' => $qr->code,
            'scanned_at' => CarbonImmutable::parse('2024-07-01T15:05:00Z')->toIso8601String(),
            'device_id' => 'venue-check',
            'checkpoint_id' => $checkpointBravo->id,
        ]);

        $allowed->assertOk();
        $allowed->assertJsonPath('data.result', 'valid');
    }

    public function test_scan_requests_are_idempotent_for_identical_payloads(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $hostess = $this->createHostess($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
            'checkin_policy' => 'single',
        ]);

        $this->assignHostessToEvent($hostess, $event);

        [$ticket, $qr] = $this->createTicketWithQr($event);
        $payload = [
            'qr_code' => $qr->code,
            'scanned_at' => CarbonImmutable::parse('2024-07-01T16:00:00Z')->toIso8601String(),
            'device_id' => 'idempotent-device',
        ];

        $firstResponse = $this->actingAs($hostess, 'api')->postJson('/scan', $payload);
        $firstResponse->assertOk();
        $firstResponse->assertJsonPath('data.result', 'valid');

        $secondResponse = $this->actingAs($hostess, 'api')->postJson('/scan', $payload);
        $secondResponse->assertOk();

        $this->assertSame($firstResponse->json('data'), $secondResponse->json('data'));
        $this->assertSame(1, Attendance::query()->where('ticket_id', $ticket->id)->count());
    }

    public function test_simultaneous_scans_produce_valid_and_duplicate_results(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $hostess = $this->createHostess($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
            'checkin_policy' => 'single',
        ]);

        $this->assignHostessToEvent($hostess, $event);

        [$ticket, $qr] = $this->createTicketWithQr($event);

        $payload = [
            'qr_code' => $qr->code,
            'scanned_at' => CarbonImmutable::parse('2024-07-01T17:00:00Z')->toIso8601String(),
            'device_id' => 'concurrent-1',
        ];

        $secondPayload = array_merge($payload, ['device_id' => 'concurrent-2']);
        $secondResponse = null;

        EventFacade::listen('eloquent.creating: '.Attendance::class, function () use (&$secondResponse, $hostess, $secondPayload): void {
            static $hasRun = false;

            if ($hasRun) {
                return;
            }

            $hasRun = true;
            $secondResponse = $this->actingAs($hostess, 'api')->postJson('/scan', $secondPayload);
        });

        $firstResponse = $this->actingAs($hostess, 'api')->postJson('/scan', $payload);

        $this->assertNotNull($secondResponse);

        $firstResponse->assertOk();
        $firstResponse->assertJsonPath('data.result', 'valid');

        $secondResponse->assertOk();
        $secondResponse->assertJsonPath('data.result', 'duplicate');

        $this->assertSame(2, Attendance::query()->where('ticket_id', $ticket->id)->count());
        $this->assertSame(
            ['duplicate', 'valid'],
            Attendance::query()
                ->where('ticket_id', $ticket->id)
                ->pluck('result')
                ->sort()
                ->values()
                ->toArray()
        );
    }

    public function test_batch_returns_multi_status_payload(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $hostess = $this->createHostess($tenant);

        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
            'checkin_policy' => 'single',
        ]);

        $this->assignHostessToEvent($hostess, $event);

        [$firstTicket, $firstQr] = $this->createTicketWithQr($event);
        [$secondTicket, $secondQr] = $this->createTicketWithQr($event);

        Attendance::query()->create([
            'event_id' => $event->id,
            'ticket_id' => $secondTicket->id,
            'guest_id' => $secondTicket->guest_id,
            'checkpoint_id' => null,
            'hostess_user_id' => $hostess->id,
            'result' => 'valid',
            'scanned_at' => CarbonImmutable::parse('2024-07-01T10:00:00Z'),
            'device_id' => 'device-initial',
            'offline' => false,
            'metadata_json' => ['reason' => 'accepted'],
        ]);

        $secondTicket->forceFill(['status' => 'used'])->save();

        $payload = [
            'scans' => [
                [
                    'qr_code' => $firstQr->code,
                    'scanned_at' => CarbonImmutable::parse('2024-07-02T10:00:00Z')->toIso8601String(),
                    'device_id' => 'batch-device-1',
                    'event_id' => $event->id,
                ],
                [
                    'qr_code' => $secondQr->code,
                    'scanned_at' => CarbonImmutable::parse('2024-07-02T10:05:00Z')->toIso8601String(),
                    'device_id' => 'batch-device-2',
                    'event_id' => $event->id,
                ],
                [
                    'qr_code' => 'UNKNOWN-CODE',
                    'scanned_at' => CarbonImmutable::parse('2024-07-02T10:10:00Z')->toIso8601String(),
                    'device_id' => 'batch-device-3',
                    'event_id' => $event->id,
                ],
            ],
        ];

        $response = $this->actingAs($hostess, 'api')->postJson('/scan/batch', $payload);

        $response->assertStatus(207);
        $response->assertJsonPath('data.0.result', 'valid');
        $response->assertJsonPath('data.0.attendance.offline', true);
        $response->assertJsonPath('data.1.result', 'duplicate');
        $response->assertJsonPath('data.2.result', 'invalid');
        $response->assertNull($response->json('data.2.attendance'));
        $response->assertNull($response->json('data.2.ticket'));
        $response->assertJsonPath('data.2.reason', 'qr_not_found');
        $response->assertJsonPath('meta.summary.valid', 1);
        $response->assertJsonPath('meta.summary.duplicate', 1);
        $response->assertJsonPath('meta.summary.errors', 1);
        $response->assertJsonPath('meta.total_scans', 3);

        $this->assertDatabaseHas('attendances', [
            'ticket_id' => $firstTicket->id,
            'result' => 'valid',
        ]);

        $this->assertDatabaseHas('attendances', [
            'ticket_id' => $secondTicket->id,
            'result' => 'duplicate',
        ]);

        $this->assertSame(3, Attendance::query()->count());
    }

    private function assignHostessToEvent(User $hostess, Event $event, ?Venue $venue = null, ?Checkpoint $checkpoint = null): void
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
