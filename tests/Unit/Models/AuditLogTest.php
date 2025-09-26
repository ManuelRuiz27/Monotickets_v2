<?php

namespace Tests\Unit\Models;

use App\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class AuditLogTest extends TestCase
{
    public function test_fill_assigns_mass_assignable_attributes(): void
    {
        $occurredAt = Carbon::now();

        $tenantId = (string) Str::ulid();
        $userId = (string) Str::ulid();

        $log = new AuditLog();
        $log->fill([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'entity' => 'user',
            'entity_id' => '01HZPY4YVK8W5F36T2V0ACM0FX',
            'action' => 'created',
            'diff_json' => ['after' => ['name' => 'Test']],
            'ip' => '127.0.0.1',
            'ua' => 'PHPUnit',
            'occurred_at' => $occurredAt,
        ]);

        $this->assertSame($tenantId, $log->tenant_id);
        $this->assertSame($userId, $log->user_id);
        $this->assertSame('user', $log->entity);
        $this->assertSame('01HZPY4YVK8W5F36T2V0ACM0FX', $log->entity_id);
        $this->assertSame('created', $log->action);
        $this->assertSame(['after' => ['name' => 'Test']], $log->diff_json);
        $this->assertSame('127.0.0.1', $log->ip);
        $this->assertSame('PHPUnit', $log->ua);
        $this->assertTrue($log->occurred_at->equalTo($occurredAt));
    }
}
