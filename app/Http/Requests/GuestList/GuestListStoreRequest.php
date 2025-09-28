<?php

namespace App\Http\Requests\GuestList;

/**
 * Validate payload for creating guest lists.
 */
class GuestListStoreRequest extends GuestListRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->guestListRules(false);
    }
}
