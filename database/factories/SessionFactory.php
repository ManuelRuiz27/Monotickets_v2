<?php

namespace Database\Factories;

use App\Models\Session;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Session>
 */
class SessionFactory extends Factory
{
    protected $model = Session::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'user_agent' => $this->faker->userAgent(),
            'ip' => $this->faker->ipv4(),
            'expires_at' => now()->addHours(2),
            'revoked_at' => null,
        ];
    }
}
