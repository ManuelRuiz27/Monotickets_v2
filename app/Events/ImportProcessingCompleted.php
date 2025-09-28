<?php

namespace App\Events;

use App\Models\Import;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an import finishes processing.
 */
class ImportProcessingCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Import $import)
    {
    }
}
