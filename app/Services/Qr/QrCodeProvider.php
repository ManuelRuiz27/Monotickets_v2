<?php

namespace App\Services\Qr;

use App\Models\Ticket;

/**
 * @phpstan-import-type GeneratedQrCode from \App\Services\Qr\GeneratedQrCode
 */

/**
 * Contract for generating secure QR codes for tickets.
 */
interface QrCodeProvider
{
    /**
     * Generate a QR code payload for the provided ticket.
     */
    public function generate(Ticket $ticket): GeneratedQrCode;
}
