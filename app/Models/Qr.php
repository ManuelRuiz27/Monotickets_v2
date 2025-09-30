<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Qr extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'ticket_id',
        'display_code',
        'payload',
        'version',
        'is_active',
        'code',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'version' => 'integer',
        'is_active' => 'boolean',
    ];

    public function getCodeAttribute(): ?string
    {
        return $this->attributes['display_code'] ?? null;
    }

    public function setCodeAttribute(?string $value): void
    {
        $this->attributes['display_code'] = $value;
    }

    /**
     * Ticket that owns the QR code.
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
