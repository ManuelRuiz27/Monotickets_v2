<?php

namespace App\Services\Qr;

use App\Models\Ticket;

/**
 * Contract for generating QR code payloads for tickets.
 */
interface QrCodeProvider
{
    /**
     * Generate a QR code payload for the provided ticket.
     *
     * @todo Replace this contract usage with a secure hash implementation that prefixes
     *       codes using a human-readable pattern such as "MT-XXXX-XXXX".
     */
    public function generate(Ticket $ticket): string;
}
