<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Tax Rate
    |--------------------------------------------------------------------------
    |
    | Configure the tax rate applied to invoice subtotals. Provide the rate as
    | a decimal value (e.g. 0.21 for 21%). When null, no global tax rate is
    | applied and plan-level tax configuration will be used instead.
    |
    */

    'tax_rate' => env('BILLING_TAX_RATE', null),
];
