<?php

namespace App\Http\Requests\Ticket;

/**
 * Validate payload for updating tickets.
 */
class TicketUpdateRequest extends TicketRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->ticketRules(true);
    }
}
