<?php

namespace App\Http\Requests\Venue;

/**
 * Validate payload for updating venues.
 */
class VenueUpdateRequest extends VenueRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->venueRules(true);
    }
}
