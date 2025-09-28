<?php

namespace App\Events;

use App\Models\Import;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an import begins processing.
 */
class ImportProcessingStarted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Import $import)
    {
    }
}
