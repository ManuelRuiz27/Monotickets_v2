<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'event_id',
        'guest_id',
        'type',
        'price_cents',
        'status',
        'seat_section',
        'seat_row',
        'seat_code',
        'issued_at',
        'expires_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'price_cents' => 'integer',
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::deleting(function (Ticket $ticket): void {
            if ($ticket->isForceDeleting()) {
                $ticket->attendances()->withTrashed()->get()->each->forceDelete();

                $qr = $ticket->qr()->withTrashed()->first();
                $qr?->forceDelete();

                return;
            }

            $ticket->attendances()->get()->each->delete();
            $ticket->qr()->first()?->delete();
        });
    }

    /**
     * Event the ticket is linked to.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Guest that owns the ticket.
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * QR associated with the ticket.
     */
    public function qr(): HasOne
    {
        return $this->hasOne(Qr::class);
    }

    /**
     * Attendance logs linked to the ticket.
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }
}
