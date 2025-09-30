<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        parent::tearDown();
        Redis::clearResolvedInstances();
        Queue::clearResolvedInstances();
        DB::clearResolvedInstances();
    }

    public function test_health_endpoint_reports_ok_status_when_dependencies_are_available(): void
    {
        DB::shouldReceive('connection')->once()->andReturnSelf();
        DB::shouldReceive('select')->once()->with('SELECT 1');

        Redis::shouldReceive('connection')->once()->andReturn(new class {
            public function ping(): string
            {
                return 'PONG';
            }
        });

        Queue::shouldReceive('connection')->once()->andReturn(new class {
            public function getConnectionName(): string
            {
                return 'sync';
            }
        });

        $response = $this->getJson('/api/health');

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');
        $response->assertJsonPath('checks.database.status', 'ok');
        $response->assertJsonPath('checks.redis.status', 'ok');
        $response->assertJsonPath('checks.queue.status', 'ok');
    }

    public function test_health_endpoint_marks_service_as_degraded_when_redis_fails(): void
    {
        config(['app.debug' => false]);

        DB::shouldReceive('connection')->once()->andReturnSelf();
        DB::shouldReceive('select')->once()->with('SELECT 1');

        Redis::shouldReceive('connection')->once()->andReturn(new class {
            public function ping(): string
            {
                throw new RuntimeException('redis down');
            }
        });

        Queue::shouldReceive('connection')->once()->andReturn(new class {
            public function getConnectionName(): string
            {
                return 'sync';
            }
        });

        $response = $this->getJson('/api/health');

        $response->assertStatus(503);
        $response->assertJsonPath('status', 'degraded');
        $response->assertJsonPath('checks.redis.status', 'error');
        $this->assertSame('unavailable', $response->json('checks.redis.message'));
    }
}
