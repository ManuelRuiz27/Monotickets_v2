<?php

namespace App\Services\Billing\Exceptions;

use App\Models\Invoice;
use RuntimeException;

class BillingPeriodAlreadyClosedException extends RuntimeException
{
    public function __construct(private readonly Invoice $invoice)
    {
        parent::__construct('The billing period has already been closed.');
    }

    public function invoice(): Invoice
    {
        return $this->invoice;
    }
}
