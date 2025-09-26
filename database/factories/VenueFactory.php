<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Venue>
 */
class VenueFactory extends Factory
{
    protected $model = Venue::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $hasCoordinates = $this->faker->boolean(60);

        return [
            'event_id' => Event::factory(),
            'name' => $this->faker->company().' Venue',
            'address' => $this->faker->optional()->address(),
            'lat' => $hasCoordinates ? $this->faker->latitude() : null,
            'lng' => $hasCoordinates ? $this->faker->longitude() : null,
            'notes' => $this->faker->optional()->sentence(),
        ];
    }
}
