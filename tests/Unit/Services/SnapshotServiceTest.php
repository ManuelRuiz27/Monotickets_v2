<?php

namespace Tests\Unit\Services;

use App\Models\Event;
use App\Models\ReportSnapshot;
use App\Models\Tenant;
use App\Services\Analytics\AnalyticsService;
use App\Services\SnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_compute_uses_cached_result_until_ttl_expires(): void
    {
        $tenant = Tenant::factory()->create();
        $event = Event::factory()->for($tenant)->create([
            'timezone' => 'UTC',
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2024-01-01T12:00:00Z'));

        $analytics = $this->createMock(AnalyticsService::class);
        $analytics->expects($this->exactly(2))
            ->method('overview')
            ->with($event->id, null, null)
            ->willReturnOnConsecutiveCalls([
                'value' => 100,
            ], [
                'value' => 200,
            ]);

        $service = new SnapshotService($analytics);

        $params = [
            'event_id' => $event->id,
            'tenant_id' => $tenant->id,
            'ttl' => 3600,
        ];

        $first = $service->compute('overview', $params);
        $this->assertSame(['value' => 100], $first);

        /** @var ReportSnapshot $snapshot */
        $snapshot = ReportSnapshot::query()->firstOrFail();
        $this->assertSame(3600, $snapshot->ttl_seconds);
        $this->assertSame(['value' => 100], $snapshot->result_json);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2024-01-01T12:30:00Z'));
        $second = $service->compute('overview', $params);
        $this->assertSame(['value' => 100], $second);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2024-01-01T13:05:00Z'));
        $third = $service->compute('overview', $params);
        $this->assertSame(['value' => 200], $third);

        $snapshot->refresh();
        $this->assertSame(['value' => 200], $snapshot->result_json);

        CarbonImmutable::setTestNow();
    }
}
