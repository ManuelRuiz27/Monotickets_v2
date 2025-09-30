<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\HostessAssignment;
use App\Models\Qr;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Qr\QrCodeProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class ScanSyncFeatureTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenant.id' => null]);
    }

    public function test_sync_endpoint_deduplicates_payload_and_processes_scans(): void
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
            'scans' => [
                [
                    'qr_code' => $qr->code,
                    'scanned_at' => CarbonImmutable::parse('2024-07-01T09:00:00Z')->toIso8601String(),
                    'device_id' => 'sync-device',
                ],
                [
                    'qr_code' => $qr->code,
                    'scanned_at' => CarbonImmutable::parse('2024-07-01T09:00:00Z')->toIso8601String(),
                    'device_id' => 'sync-device',
                ],
                [
                    'qr_code' => $qr->code,
                    'scanned_at' => CarbonImmutable::parse('2024-07-01T09:05:00Z')->toIso8601String(),
                    'device_id' => 'sync-device',
                ],
            ],
        ];

        $response = $this->actingAs($hostess, 'api')->postJson('/scans/sync', $payload);
        $response->assertStatus(207);

        $response->assertJsonPath('meta.summary.valid', 1);
        $response->assertJsonPath('meta.summary.duplicate', 1);
        $response->assertJsonPath('meta.summary.deduplicated', 1);
        $response->assertJsonPath('meta.summary.errors', 0);
        $response->assertJsonPath('meta.total_scans', 3);
        $response->assertJsonPath('meta.processed_scans', 2);

        $responses = collect($response->json('data'))->keyBy('index');
        $this->assertSame('valid', $responses[0]['result']);
        $this->assertSame('ignored', $responses[1]['result']);
        $this->assertSame('duplicate', $responses[2]['result']);
        $this->assertSame(0, $responses[1]['deduplicated_with']);
        $this->assertSame('duplicate_payload', $responses[1]['reason']);

        $this->assertSame(2, Attendance::query()->where('ticket_id', $ticket->id)->count());
    }

    /**
     * @return array{Ticket, Qr}
     */
    private function createTicketWithQr(Event $event, array $overrides = []): array
    {
        $guest = $event->guests()->create([
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

    private function assignHostessToEvent(User $hostess, Event $event): HostessAssignment
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
