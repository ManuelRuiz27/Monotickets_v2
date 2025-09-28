<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\Tenant;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class TicketIssuingFeatureTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenant.id' => null]);
    }

    public function test_guest_ticket_issue_limit_returns_conflict_when_exceeded(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
        ]);

        $guest = Guest::query()->create([
            'event_id' => $event->id,
            'full_name' => 'Limit Tester',
            'allow_plus_ones' => true,
            'plus_ones_limit' => 2,
        ]);

        for ($i = 0; $i < 3; $i++) {
            $response = $this->actingAs($organizer, 'api')->postJson(
                sprintf('/guests/%s/tickets', $guest->id)
            );

            $response->assertCreated();
        }

        $this->assertSame(3, Ticket::query()->where('guest_id', $guest->id)->count());

        $response = $this->actingAs($organizer, 'api')->postJson(
            sprintf('/guests/%s/tickets', $guest->id)
        );

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'TICKET_LIMIT_REACHED');
        $response->assertJsonPath('error.details.limit', 3);
    }

    public function test_ticket_store_rejects_duplicate_seat_with_conflict_status(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $event = Event::factory()->for($tenant)->create([
            'organizer_user_id' => $organizer->id,
            'status' => 'published',
        ]);

        $firstGuest = Guest::query()->create([
            'event_id' => $event->id,
            'full_name' => 'Seated Guest One',
        ]);

        $secondGuest = Guest::query()->create([
            'event_id' => $event->id,
            'full_name' => 'Seated Guest Two',
        ]);

        $seatPayload = [
            'seat_section' => 'A',
            'seat_row' => '1',
            'seat_code' => '01',
        ];

        $firstResponse = $this->actingAs($organizer, 'api')->postJson(
            sprintf('/guests/%s/tickets', $firstGuest->id),
            $seatPayload
        );

        $firstResponse->assertCreated();

        $conflictResponse = $this->actingAs($organizer, 'api')->postJson(
            sprintf('/guests/%s/tickets', $secondGuest->id),
            $seatPayload
        );

        $conflictResponse->assertStatus(409);
        $conflictResponse->assertJsonPath('error.code', 'SEAT_CONFLICT');
        $conflictResponse->assertJsonPath('error.details.seat_code', '01');

        $this->assertSame(1, Ticket::query()->where('event_id', $event->id)->count());
    }
}
