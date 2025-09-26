<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('+1 day', '+1 month');
        $end = (clone $start)->modify('+'.random_int(2, 6).' hours');

        return [
            'tenant_id' => Tenant::factory(),
            'organizer_user_id' => null,
            'code' => strtoupper(Str::random(8)),
            'name' => $this->faker->sentence(3),
            'description' => $this->faker->optional()->paragraph(),
            'start_at' => $start,
            'end_at' => $end,
            'timezone' => $this->faker->timezone(),
            'status' => $this->faker->randomElement(['draft', 'published', 'archived']),
            'capacity' => $this->faker->optional()->numberBetween(50, 1000),
            'checkin_policy' => $this->faker->randomElement(['single', 'multiple']),
            'settings_json' => [
                'language' => $this->faker->randomElement(['en', 'es', 'pt']),
            ],
        ];
    }

    /**
     * Configure the factory.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Event $event): void {
            $tenant = null;

            if ($event->tenant_id) {
                $tenant = Tenant::withTrashed()->find($event->tenant_id);
            }

            if (!$tenant) {
                $tenant = Tenant::factory()->create();
                $event->tenant_id = $tenant->id;
            }

            if ($event->organizer_user_id) {
                $organizerTenantId = User::withTrashed()
                    ->whereKey($event->organizer_user_id)
                    ->value('tenant_id');

                if ($organizerTenantId === $tenant->id) {
                    return;
                }
            }

            $organizer = User::factory()->for($tenant)->create();

            $event->organizer_user_id = $organizer->id;
        });
    }
}
