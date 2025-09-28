<?php

namespace App\Http\Requests\Guest;

/**
 * Validate payload for creating guests.
 */
class GuestStoreRequest extends GuestRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->guestRules(false);
    }
}
