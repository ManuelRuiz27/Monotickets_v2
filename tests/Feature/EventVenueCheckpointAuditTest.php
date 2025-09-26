<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Checkpoint;
use App\Models\Event;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Concerns\CreatesUsers;

class EventVenueCheckpointAuditTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenant.id' => null]);
    }

    public function test_event_creation_records_audit_log_entry(): void
    {
        $tenant = Tenant::factory()->create();
        $superAdmin = $this->createSuperAdmin();
        $organizer = User::factory()->create(['tenant_id' => $tenant->id]);

        $payload = [
            'tenant_id' => $tenant->id,
            'organizer_user_id' => $organizer->id,
            'code' => 'EVT-1000',
            'name' => 'Product Launch',
            'description' => 'Launch event description',
            'start_at' => CarbonImmutable::now()->addDays(2)->toISOString(),
            'end_at' => CarbonImmutable::now()->addDays(2)->addHours(3)->toISOString(),
            'timezone' => 'UTC',
            'status' => 'draft',
            'capacity' => 150,
            'checkin_policy' => 'single',
            'settings_json' => ['language' => 'en'],
        ];

        $response = $this->actingAs($superAdmin, 'api')
            ->postJson('/events', $payload);

        $response->assertCreated();
        $eventId = $response->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'event',
            'entity_id' => $eventId,
            'action' => 'created',
        ]);

        $log = AuditLog::query()
            ->where('entity', 'event')
            ->where('entity_id', $eventId)
            ->where('action', 'created')
            ->firstOrFail();

        $this->assertSame('Product Launch', $log->diff_json['after']['name']);
        $this->assertSame($tenant->id, $log->tenant_id);
    }

    public function test_event_update_records_changed_fields_in_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $superAdmin = $this->createSuperAdmin();
        $organizer = User::factory()->create(['tenant_id' => $tenant->id]);

        $event = Event::factory()
            ->for($tenant)
            ->for($organizer, 'organizer')
            ->create([
                'code' => 'EVT-ORIG',
                'name' => 'Original Name',
                'status' => 'draft',
                'checkin_policy' => 'single',
            ]);

        $payload = [
            'name' => 'Updated Event Name',
            'status' => 'published',
        ];

        $response = $this->actingAs($superAdmin, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->patchJson('/events/' . $event->id, $payload);

        $response->assertOk();

        $log = AuditLog::query()
            ->where('entity', 'event')
            ->where('entity_id', $event->id)
            ->where('action', 'updated')
            ->latest('occurred_at')
            ->firstOrFail();

        $this->assertSame('Original Name', $log->diff_json['changes']['name']['before']);
        $this->assertSame('Updated Event Name', $log->diff_json['changes']['name']['after']);
        $this->assertSame('draft', $log->diff_json['changes']['status']['before']);
        $this->assertSame('published', $log->diff_json['changes']['status']['after']);
    }

    public function test_event_deletion_records_audit_snapshot(): void
    {
        $tenant = Tenant::factory()->create();
        $superAdmin = $this->createSuperAdmin();
        $organizer = User::factory()->create(['tenant_id' => $tenant->id]);

        $event = Event::factory()
            ->for($tenant)
            ->for($organizer, 'organizer')
            ->create([
                'code' => 'EVT-DEL',
                'name' => 'Delete Me',
            ]);

        $response = $this->actingAs($superAdmin, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->deleteJson('/events/' . $event->id);

        $response->assertNoContent();
        $this->assertSoftDeleted('events', ['id' => $event->id]);

        $log = AuditLog::query()
            ->where('entity', 'event')
            ->where('entity_id', $event->id)
            ->where('action', 'deleted')
            ->firstOrFail();

        $this->assertSame('Delete Me', $log->diff_json['before']['name']);
    }

    public function test_venue_deletion_soft_deletes_checkpoints_and_logs(): void
    {
        $tenant = Tenant::factory()->create();
        $superAdmin = $this->createSuperAdmin();
        $organizer = User::factory()->create(['tenant_id' => $tenant->id]);

        $event = Event::factory()
            ->for($tenant)
            ->for($organizer, 'organizer')
            ->create();

        $venue = Venue::factory()->for($event)->create([
            'name' => 'Main Hall',
        ]);

        $checkpointA = Checkpoint::factory()->create([
            'event_id' => $event->id,
            'venue_id' => $venue->id,
            'name' => 'Gate A',
        ]);

        $checkpointB = Checkpoint::factory()->create([
            'event_id' => $event->id,
            'venue_id' => $venue->id,
            'name' => 'Gate B',
        ]);

        $response = $this->actingAs($superAdmin, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->deleteJson('/events/' . $event->id . '/venues/' . $venue->id);

        $response->assertNoContent();

        $this->assertSoftDeleted('venues', ['id' => $venue->id]);
        $this->assertSoftDeleted('checkpoints', ['id' => $checkpointA->id]);
        $this->assertSoftDeleted('checkpoints', ['id' => $checkpointB->id]);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'venue',
            'entity_id' => $venue->id,
            'action' => 'deleted',
        ]);

        $checkpointLogs = AuditLog::query()
            ->where('entity', 'checkpoint')
            ->whereIn('entity_id', [$checkpointA->id, $checkpointB->id])
            ->where('action', 'deleted')
            ->count();

        $this->assertSame(2, $checkpointLogs);
    }

    public function test_checkpoint_crud_actions_are_audited(): void
    {
        $tenant = Tenant::factory()->create();
        $superAdmin = $this->createSuperAdmin();
        $organizer = User::factory()->create(['tenant_id' => $tenant->id]);

        $event = Event::factory()
            ->for($tenant)
            ->for($organizer, 'organizer')
            ->create();

        $venue = Venue::factory()->for($event)->create();

        $createPayload = [
            'name' => 'Entrance',
            'description' => 'Main door',
        ];

        $createResponse = $this->actingAs($superAdmin, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->postJson('/events/' . $event->id . '/venues/' . $venue->id . '/checkpoints', $createPayload);

        $createResponse->assertCreated();
        $checkpointId = $createResponse->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'checkpoint',
            'entity_id' => $checkpointId,
            'action' => 'created',
        ]);

        $updatePayload = ['name' => 'Updated Entrance'];

        $updateResponse = $this->actingAs($superAdmin, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->patchJson('/events/' . $event->id . '/venues/' . $venue->id . '/checkpoints/' . $checkpointId, $updatePayload);

        $updateResponse->assertOk();

        $updateLog = AuditLog::query()
            ->where('entity', 'checkpoint')
            ->where('entity_id', $checkpointId)
            ->where('action', 'updated')
            ->firstOrFail();

        $this->assertSame('Entrance', $updateLog->diff_json['changes']['name']['before']);
        $this->assertSame('Updated Entrance', $updateLog->diff_json['changes']['name']['after']);

        $deleteResponse = $this->actingAs($superAdmin, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->deleteJson('/events/' . $event->id . '/venues/' . $venue->id . '/checkpoints/' . $checkpointId);

        $deleteResponse->assertNoContent();

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'checkpoint',
            'entity_id' => $checkpointId,
            'action' => 'deleted',
        ]);
    }

}
