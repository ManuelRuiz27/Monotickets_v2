<?php

namespace Database\Factories;

use App\Models\Checkpoint;
use App\Models\Event;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Checkpoint>
 */
class CheckpointFactory extends Factory
{
    protected $model = Checkpoint::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'venue_id' => Venue::factory(),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->optional()->sentence(12),
        ];
    }

    /**
     * Ensure event and venue relationship stay aligned.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Checkpoint $checkpoint): void {
            $venue = $checkpoint->venue;

            if ($venue && $venue->event_id !== $checkpoint->event_id) {
                $checkpoint->forceFill(['event_id' => $venue->event_id])->save();
            }
        });
    }
}
