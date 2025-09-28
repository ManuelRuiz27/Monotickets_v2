<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Qr;
use App\Models\Tenant;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class TicketQrFeatureTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenant.id' => null]);
    }

    public function test_show_returns_qr_details_for_ticket(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
        ]);
        $guest = Guest::query()->create([
            'event_id' => $event->id,
            'full_name' => 'Guest One',
            'email' => 'guest@example.com',
        ]);
        $ticket = Ticket::query()->create([
            'event_id' => $event->id,
            'guest_id' => $guest->id,
            'type' => 'general',
            'price_cents' => 0,
            'status' => 'issued',
            'issued_at' => now(),
        ]);
        $qr = Qr::query()->create([
            'ticket_id' => $ticket->id,
            'code' => 'MT-TEST-0001',
            'version' => 3,
            'is_active' => true,
        ]);

        $response = $this->actingAs($organizer, 'api')->getJson(sprintf('/tickets/%s/qr', $ticket->id));

        $response->assertOk();
        $response->assertJsonPath('data.id', $qr->id);
        $response->assertJsonPath('data.code', 'MT-TEST-0001');
        $response->assertJsonPath('data.version', 3);
    }

    public function test_store_creates_qr_when_missing(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
        ]);
        $guest = Guest::query()->create([
            'event_id' => $event->id,
            'full_name' => 'Guest Two',
        ]);
        $ticket = Ticket::query()->create([
            'event_id' => $event->id,
            'guest_id' => $guest->id,
            'type' => 'general',
            'price_cents' => 0,
            'status' => 'issued',
            'issued_at' => now(),
        ]);

        $response = $this->actingAs($organizer, 'api')->postJson(sprintf('/tickets/%s/qr', $ticket->id));

        $response->assertCreated();
        $this->assertDatabaseHas('qrs', [
            'ticket_id' => $ticket->id,
            'version' => 1,
            'is_active' => true,
        ]);

        $auditLog = AuditLog::query()->where('entity', 'qr')->where('entity_id', $response->json('data.id'))->first();
        $this->assertNotNull($auditLog);
        $this->assertSame('rotated', $auditLog->action);
    }

    public function test_store_rotates_existing_qr(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
        ]);
        $guest = Guest::query()->create([
            'event_id' => $event->id,
            'full_name' => 'Guest Three',
        ]);
        $ticket = Ticket::query()->create([
            'event_id' => $event->id,
            'guest_id' => $guest->id,
            'type' => 'general',
            'price_cents' => 0,
            'status' => 'issued',
            'issued_at' => now(),
        ]);
        $qr = Qr::query()->create([
            'ticket_id' => $ticket->id,
            'code' => 'MT-TEST-0002',
            'version' => 4,
            'is_active' => true,
        ]);

        $response = $this->actingAs($organizer, 'api')->postJson(sprintf('/tickets/%s/qr', $ticket->id));

        $response->assertOk();
        $response->assertJsonPath('data.version', 5);
        $response->assertJsonPath('data.id', $qr->id);
        $response->assertJsonPath('data.ticket_id', $ticket->id);
        $this->assertDatabaseHas('qrs', [
            'id' => $qr->id,
            'version' => 5,
            'is_active' => true,
        ]);
        $this->assertNotSame('MT-TEST-0002', $response->json('data.code'));
    }

    public function test_store_rejects_ticket_that_is_not_issued(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
        ]);
        $guest = Guest::query()->create([
            'event_id' => $event->id,
            'full_name' => 'Guest Four',
        ]);
        $ticket = Ticket::query()->create([
            'event_id' => $event->id,
            'guest_id' => $guest->id,
            'type' => 'general',
            'price_cents' => 0,
            'status' => 'revoked',
            'issued_at' => now(),
        ]);

        $response = $this->actingAs($organizer, 'api')->postJson(sprintf('/tickets/%s/qr', $ticket->id));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['ticket_id']);
    }
}
