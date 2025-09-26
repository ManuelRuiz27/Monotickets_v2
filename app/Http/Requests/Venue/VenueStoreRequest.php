<?php

namespace App\Http\Requests\Venue;

/**
 * Validate payload for creating venues.
 */
class VenueStoreRequest extends VenueRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->venueRules(false);
    }
}
