<?php

namespace App\Http\Requests\GuestList;

/**
 * Validate payload for updating guest lists.
 */
class GuestListUpdateRequest extends GuestListRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->guestListRules(true);
    }
}
