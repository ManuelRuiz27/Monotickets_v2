<?php

namespace Tests\Feature;

use App\Models\Checkpoint;
use App\Models\Event;
use App\Models\Tenant;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Concerns\CreatesUsers;
use Tests\Support\Payloads\CheckpointPayloadFactory;
use Tests\Support\Payloads\EventPayloadFactory;
use Tests\Support\Payloads\VenuePayloadFactory;

class EventVenueCheckpointFeatureTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenant.id' => null]);
    }

    public function test_organizer_lists_events_for_their_tenant_only(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $organizerA = $this->createOrganizer($tenantA);
        $organizerB = $this->createOrganizer($tenantB);

        Event::factory()->for($tenantA)->for($organizerA, 'organizer')->create(['code' => 'TEN-A-1']);
        Event::factory()->for($tenantA)->for($organizerA, 'organizer')->create(['code' => 'TEN-A-2']);
        Event::factory()->for($tenantB)->for($organizerB, 'organizer')->create(['code' => 'TEN-B-1']);

        $response = $this->actingAs($organizerA, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenantA->id])
            ->getJson('/events');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $this->assertTrue(collect($response->json('data'))
            ->every(fn (array $event) => $event['tenant_id'] === $tenantA->id));
    }

    public function test_organizer_request_without_tenant_header_is_forbidden(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);

        $response = $this->actingAs($organizer, 'api')->getJson('/events');

        $response->assertForbidden();
    }

    public function test_organizer_request_with_mismatched_tenant_header_is_forbidden(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);

        $response = $this->actingAs($organizer, 'api')
            ->withHeaders(['X-Tenant-ID' => $otherTenant->id])
            ->getJson('/events');

        $response->assertForbidden();
    }

    public function test_superadmin_lists_events_across_all_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $organizerA = $this->createOrganizer($tenantA);
        $organizerB = $this->createOrganizer($tenantB);

        Event::factory()->for($tenantA)->for($organizerA, 'organizer')->count(2)->create();
        Event::factory()->for($tenantB)->for($organizerB, 'organizer')->create();

        $superAdmin = $this->createSuperAdmin();

        $response = $this->actingAs($superAdmin, 'api')->getJson('/events');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 3);
    }

    public function test_superadmin_impersonates_tenant_to_list_events(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $organizerA = $this->createOrganizer($tenantA);
        $organizerB = $this->createOrganizer($tenantB);

        Event::factory()->for($tenantA)->for($organizerA, 'organizer')->count(2)->create();
        Event::factory()->for($tenantB)->for($organizerB, 'organizer')->create();

        $superAdmin = $this->createSuperAdmin();

        $response = $this->actingAs($superAdmin, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenantA->id])
            ->getJson('/events');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 2);
        $this->assertTrue(collect($response->json('data'))
            ->every(fn (array $event) => $event['tenant_id'] === $tenantA->id));
    }

    public function test_superadmin_impersonation_cannot_access_event_from_other_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $organizerA = $this->createOrganizer($tenantA);
        $organizerB = $this->createOrganizer($tenantB);

        $eventA = Event::factory()->for($tenantA)->for($organizerA, 'organizer')->create();
        $eventB = Event::factory()->for($tenantB)->for($organizerB, 'organizer')->create();

        $superAdmin = $this->createSuperAdmin();

        $response = $this->actingAs($superAdmin, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenantA->id])
            ->getJson('/events/' . $eventB->id);

        $response->assertNotFound();

        $allowed = $this->actingAs($superAdmin, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenantA->id])
            ->getJson('/events/' . $eventA->id);

        $allowed->assertOk();
        $allowed->assertJsonPath('data.id', $eventA->id);
    }

    public function test_event_index_applies_status_date_and_search_filters(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $superAdmin = $this->createSuperAdmin();

        Event::factory()->for($tenant)->for($organizer, 'organizer')->create([
            'code' => 'FILTER-1',
            'name' => 'Tech Expo',
            'status' => 'draft',
            'start_at' => CarbonImmutable::parse('2024-02-01'),
        ]);

        Event::factory()->for($tenant)->for($organizer, 'organizer')->create([
            'code' => 'FILTER-2',
            'name' => 'Music Fest',
            'status' => 'published',
            'start_at' => CarbonImmutable::parse('2024-02-10'),
            'description' => 'A vibrant music event',
        ]);

        Event::factory()->for($tenant)->for($organizer, 'organizer')->create([
            'code' => 'FILTER-3',
            'name' => 'Archive Summit',
            'status' => 'archived',
            'start_at' => CarbonImmutable::parse('2024-03-05'),
        ]);

        $response = $this->actingAs($superAdmin, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->getJson('/events?' . http_build_query([
            'status' => 'published',
            'from' => '2024-02-01',
            'to' => '2024-02-28',
            'search' => 'music',
        ]));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.code', 'FILTER-2');
    }

    public function test_superadmin_can_create_event_with_valid_payload(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $superAdmin = $this->createSuperAdmin();

        $payload = EventPayloadFactory::make($tenant, $organizer, [
            'code' => 'EVT-VALID',
            'name' => 'Valid Event',
        ]);

        $response = $this->actingAs($superAdmin, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->postJson('/events', $payload);

        $response->assertCreated();
        $response->assertJsonPath('data.code', 'EVT-VALID');
        $this->assertDatabaseHas('events', [
            'tenant_id' => $tenant->id,
            'code' => 'EVT-VALID',
        ]);

        $eventId = $response->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'event',
            'entity_id' => $eventId,
            'action' => 'created',
        ]);
    }

    public function test_cannot_create_event_with_duplicate_code_for_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $superAdmin = $this->createSuperAdmin();

        Event::factory()->for($tenant)->for($organizer, 'organizer')->create([
            'code' => 'EVT-DUP',
        ]);

        $payload = EventPayloadFactory::make($tenant, $organizer, [
            'code' => 'EVT-DUP',
        ]);

        $response = $this->actingAs($superAdmin, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->postJson('/events', $payload);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['code']);
    }

    public function test_start_at_must_precede_end_at_when_creating_event(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $superAdmin = $this->createSuperAdmin();

        $start = CarbonImmutable::now()->addDays(5);
        $payload = EventPayloadFactory::make($tenant, $organizer, [
            'start_at' => $start->toISOString(),
            'end_at' => $start->toISOString(),
        ]);

        $response = $this->actingAs($superAdmin, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->postJson('/events', $payload);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['end_at']);
    }

    public function test_superadmin_can_create_venue_within_event_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $superAdmin = $this->createSuperAdmin();

        $event = Event::factory()->for($tenant)->for($organizer, 'organizer')->create();
        $payload = VenuePayloadFactory::make(['name' => 'Conference Hall']);

        $response = $this->actingAs($superAdmin, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->postJson('/events/' . $event->id . '/venues', $payload);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Conference Hall');

        $this->assertDatabaseHas('venues', [
            'event_id' => $event->id,
            'name' => 'Conference Hall',
        ]);
    }

    public function test_organizer_cannot_create_venue_for_event_of_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $organizerA = $this->createOrganizer($tenantA);
        $organizerB = $this->createOrganizer($tenantB);

        $event = Event::factory()->for($tenantA)->for($organizerA, 'organizer')->create();
        $payload = VenuePayloadFactory::make(['name' => 'Attempted Venue']);

        $response = $this->actingAs($organizerB, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenantB->id])
            ->postJson('/events/' . $event->id . '/venues', $payload);

        $response->assertNotFound();
        $this->assertDatabaseMissing('venues', [
            'event_id' => $event->id,
            'name' => 'Attempted Venue',
        ]);
    }

    public function test_checkpoint_creation_requires_existing_venue_in_event(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $superAdmin = $this->createSuperAdmin();

        $event = Event::factory()->for($tenant)->for($organizer, 'organizer')->create();
        $otherEvent = Event::factory()->create();
        $otherVenue = Venue::factory()->for($otherEvent)->create();

        $response = $this->actingAs($superAdmin, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->postJson('/events/' . $event->id . '/venues/' . $otherVenue->id . '/checkpoints', CheckpointPayloadFactory::make());

        $response->assertNotFound();
    }

    public function test_checkpoint_creation_succeeds_and_records_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $superAdmin = $this->createSuperAdmin();

        $event = Event::factory()->for($tenant)->for($organizer, 'organizer')->create();
        $venue = Venue::factory()->for($event)->create();

        $payload = CheckpointPayloadFactory::make(['name' => 'Security Gate']);

        $response = $this->actingAs($superAdmin, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->postJson('/events/' . $event->id . '/venues/' . $venue->id . '/checkpoints', $payload);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Security Gate');

        $checkpointId = $response->json('data.id');

        $this->assertDatabaseHas('checkpoints', [
            'event_id' => $event->id,
            'venue_id' => $venue->id,
            'name' => 'Security Gate',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'checkpoint',
            'entity_id' => $checkpointId,
            'action' => 'created',
        ]);
    }

    public function test_soft_deleting_venue_cascades_and_logs_audit_entries(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $superAdmin = $this->createSuperAdmin();

        $event = Event::factory()->for($tenant)->for($organizer, 'organizer')->create();
        $venue = Venue::factory()->for($event)->create(['name' => 'Temporary Venue']);

        $checkpoint = Checkpoint::factory()->create([
            'event_id' => $event->id,
            'venue_id' => $venue->id,
        ]);

        $response = $this->actingAs($superAdmin, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->deleteJson('/events/' . $event->id . '/venues/' . $venue->id);

        $response->assertNoContent();

        $this->assertSoftDeleted('venues', ['id' => $venue->id]);
        $this->assertSoftDeleted('checkpoints', ['id' => $checkpoint->id]);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'venue',
            'entity_id' => $venue->id,
            'action' => 'deleted',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'checkpoint',
            'entity_id' => $checkpoint->id,
            'action' => 'deleted',
        ]);
    }
}
