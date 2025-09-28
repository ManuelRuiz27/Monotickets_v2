<?php

namespace App\Http\Requests\Guest;

/**
 * Validate payload for updating guests.
 */
class GuestUpdateRequest extends GuestRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->guestRules(true);
    }
}
