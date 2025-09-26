<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Venue extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'event_id',
        'name',
        'address',
        'lat',
        'lng',
        'notes',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
    ];

    /**
     * Scope the query to venues whose event belongs to the given tenant.
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->whereHas('event', function (Builder $eventQuery) use ($tenantId): void {
            $eventQuery->where('tenant_id', $tenantId);
        });
    }

    /**
     * Event that owns the venue.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Checkpoints associated with the venue.
     */
    public function checkpoints(): HasMany
    {
        return $this->hasMany(Checkpoint::class);
    }
}
