<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $code = $this->faker->randomElement(['superadmin', 'organizer', 'hostess', 'tenant_owner']);

        return [
            'code' => $code,
            'name' => ucfirst($code),
            'description' => $this->faker->sentence(),
        ];
    }
}
