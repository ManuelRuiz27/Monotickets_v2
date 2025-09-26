<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'entity' => 'user',
            'entity_id' => (string) Str::ulid(),
            'action' => $this->faker->randomElement(['created', 'updated', 'deleted']),
            'diff_json' => ['before' => [], 'after' => []],
            'ip' => $this->faker->ipv4(),
            'ua' => $this->faker->userAgent(),
            'occurred_at' => now(),
        ];
    }
}
