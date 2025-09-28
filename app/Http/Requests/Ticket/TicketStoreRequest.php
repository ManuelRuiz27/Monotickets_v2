<?php

namespace App\Http\Requests\Ticket;

/**
 * Validate payload for issuing tickets.
 */
class TicketStoreRequest extends TicketRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->ticketRules(false);
        unset($rules['status']);

        return $rules;
    }
}
