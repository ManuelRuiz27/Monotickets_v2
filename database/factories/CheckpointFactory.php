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
            'event_id' => null,
            'venue_id' => null,
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->optional()->sentence(12),
        ];
    }

    /**
     * Ensure event and venue relationship stay aligned.
     */
    public function configure(): static
    {
        return $this
            ->afterMaking(function (Checkpoint $checkpoint): void {
                if (!$checkpoint->venue_id) {
                    $event = Event::factory()->create();
                    $venue = Venue::factory()->for($event)->create();

                    $checkpoint->event_id = $event->id;
                    $checkpoint->venue_id = $venue->id;

                    return;
                }

                if (!$checkpoint->event_id) {
                    $checkpoint->event_id = Venue::withTrashed()
                        ->whereKey($checkpoint->venue_id)
                        ->value('event_id');
                }
            })
            ->afterCreating(function (Checkpoint $checkpoint): void {
                if ($checkpoint->event_id === null && $checkpoint->venue_id) {
                    $eventId = Venue::withTrashed()
                        ->whereKey($checkpoint->venue_id)
                        ->value('event_id');

                    if ($eventId) {
                        $checkpoint->forceFill(['event_id' => $eventId])->save();
                    }
                }
            });
    }
}
