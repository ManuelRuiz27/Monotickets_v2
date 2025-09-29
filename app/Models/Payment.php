<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'invoice_id',
        'provider',
        'provider_charge_id',
        'amount_cents',
        'currency',
        'status',
        'processed_at',
        'error_msg',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'amount_cents' => 'integer',
        'processed_at' => 'immutable_datetime',
    ];

    /**
     * Invoice associated with the payment.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
