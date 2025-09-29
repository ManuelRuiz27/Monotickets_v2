<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_VOID = 'void';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'period_start',
        'period_end',
        'subtotal_cents',
        'tax_cents',
        'total_cents',
        'status',
        'issued_at',
        'paid_at',
        'due_at',
        'line_items_json',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'period_start' => 'immutable_datetime',
        'period_end' => 'immutable_datetime',
        'issued_at' => 'immutable_datetime',
        'paid_at' => 'immutable_datetime',
        'due_at' => 'immutable_datetime',
        'subtotal_cents' => 'integer',
        'tax_cents' => 'integer',
        'total_cents' => 'integer',
        'line_items_json' => 'array',
    ];

    /**
     * Tenant that owns the invoice.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Payments recorded against the invoice.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
