<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name . '-' . $this->faker->unique()->lexify('??')),
            'status' => $this->faker->randomElement(['active', 'inactive']),
            'plan' => $this->faker->randomElement(['free', 'basic', 'pro']),
            'settings_json' => [
                'timezone' => $this->faker->timezone(),
                'branding' => [
                    'logo_url' => $this->faker->imageUrl(),
                    'colors' => [
                        'primary' => sprintf('#%06X', $this->faker->numberBetween(0, 0xFFFFFF)),
                        'accent' => sprintf('#%06X', $this->faker->numberBetween(0, 0xFFFFFF)),
                        'bg' => sprintf('#%06X', $this->faker->numberBetween(0, 0xFFFFFF)),
                        'text' => sprintf('#%06X', $this->faker->numberBetween(0, 0xFFFFFF)),
                    ],
                    'email_from' => $this->faker->companyEmail(),
                    'email_reply_to' => $this->faker->companyEmail(),
                ],
            ],
        ];
    }
}
