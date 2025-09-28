<?php

namespace App\Services\Qr;

use App\Models\Ticket;
use Illuminate\Support\Str;

/**
 * Internal QR code provider placeholder.
 */
class InternalQrCodeProvider implements QrCodeProvider
{
    public function generate(Ticket $ticket): string
    {
        // TODO: Replace this placeholder implementation with a secure hash generator
        //       that prefixes the code with a readable format like "MT-XXXX-XXXX".
        return sprintf('MT-%s-%s', Str::upper(Str::random(4)), Str::upper(Str::random(4)));
    }
}
