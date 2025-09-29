<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'code' => 'plan-' . Str::slug($this->faker->unique()->words(2, true)),
            'name' => $this->faker->sentence(3),
            'price_cents' => $this->faker->numberBetween(1000, 10000),
            'billing_cycle' => 'monthly',
            'limits_json' => [
                'included_users' => 5,
                'included_scans' => 1000,
                'user_overage_price_cents' => 200,
                'scan_overage_price_cents' => 2,
            ],
            'features_json' => [],
            'is_active' => true,
        ];
    }
}
