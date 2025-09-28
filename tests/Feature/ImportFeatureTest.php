<?php

namespace Tests\Feature;

use App\Jobs\ProcessImportJob;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class ImportFeatureTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenant.id' => null]);
    }

    public function test_organizer_can_queue_import_and_process_rows(): void
    {
        $tenant = Tenant::factory()->create();
        $organizer = $this->createOrganizer($tenant);
        $event = Event::factory()->for($tenant)->for($organizer, 'organizer')->create();

        $csvPath = tempnam(sys_get_temp_dir(), 'import');
        file_put_contents($csvPath, "Name,Email\nAlice,alice@example.com\nBob,bob@example.com\nDuplicate,alice@example.com\n");

        $payload = [
            'source' => 'csv',
            'file_url' => $csvPath,
            'mapping' => [
                'full_name' => 'Name',
                'email' => 'Email',
            ],
            'options' => [
                'dedupe_by_email' => true,
            ],
        ];

        Queue::fake();

        $response = $this->actingAs($organizer, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->postJson('/events/' . $event->id . '/imports', $payload);

        $response->assertStatus(202);
        $response->assertJsonPath('data.event_id', $event->id);

        $importId = $response->json('data.id');

        $capturedJob = null;

        Queue::assertPushed(ProcessImportJob::class, function (ProcessImportJob $job) use (&$capturedJob) {
            $capturedJob = $job;

            return true;
        });

        $this->assertNotNull($capturedJob);
        $capturedJob->handle();

        $this->assertDatabaseHas('imports', [
            'id' => $importId,
            'status' => 'failed',
            'rows_total' => 3,
            'rows_ok' => 2,
            'rows_failed' => 1,
        ]);

        $this->assertDatabaseHas('guests', [
            'event_id' => $event->id,
            'email' => 'alice@example.com',
        ]);

        $this->assertDatabaseHas('guests', [
            'event_id' => $event->id,
            'email' => 'bob@example.com',
        ]);

        $this->assertSame(2, Guest::query()->where('event_id', $event->id)->count());

        $statusResponse = $this->actingAs($organizer, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->getJson('/imports/' . $importId);

        $statusResponse->assertOk();
        $statusResponse->assertJsonPath('data.status', 'failed');
        $statusResponse->assertJsonPath('data.rows_total', 3);
        $statusResponse->assertJsonPath('data.progress', 1);
        $statusResponse->assertJsonPath('data.report_file_url', fn ($value) => ! empty($value));

        $rowsResponse = $this->actingAs($organizer, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->getJson('/imports/' . $importId . '/rows?status=failed');

        $rowsResponse->assertOk();
        $rowsResponse->assertJsonCount(1, 'data');
        $rowsResponse->assertJsonPath('data.0.status', 'failed');
        $rowsResponse->assertJsonPath('data.0.row_num', 3);
        $rowsResponse->assertJsonPath('data.0.error_msg', 'duplicated email');

        $auditLogs = AuditLog::query()
            ->where('entity', 'import')
            ->where('entity_id', $importId)
            ->orderBy('occurred_at')
            ->get();

        $this->assertCount(2, $auditLogs);
        $this->assertSame('import_started', $auditLogs->first()->action);
        $this->assertSame('processing', $auditLogs->first()->diff_json['status'] ?? null);

        $completedAudit = $auditLogs->last();
        $this->assertSame('import_completed', $completedAudit->action);
        $this->assertSame('failed', $completedAudit->diff_json['status'] ?? null);
        $this->assertSame(3, $completedAudit->diff_json['rows_total'] ?? null);
        $this->assertSame(2, $completedAudit->diff_json['rows_ok'] ?? null);
        $this->assertSame(1, $completedAudit->diff_json['rows_failed'] ?? null);

        unlink($csvPath);
    }
}
