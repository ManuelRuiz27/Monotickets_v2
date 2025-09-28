<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Assignment that links hostess users to events, venues, or checkpoints.
 */
class HostessAssignment extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'hostess_user_id',
        'event_id',
        'venue_id',
        'checkpoint_id',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Scope assignments that belong to the provided tenant identifier.
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope assignments that are currently active for the given instant.
     */
    public function scopeCurrentlyActive(Builder $query, ?Carbon $now = null): Builder
    {
        $now = $now ?? Carbon::now();

        return $query
            ->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where(function (Builder $constraint) use ($now): void {
                $constraint
                    ->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $now);
            });
    }

    /**
     * Tenant that owns the assignment.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Hostess user associated with the assignment.
     */
    public function hostess(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hostess_user_id');
    }

    /**
     * Event the assignment is scoped to.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Venue assigned to the hostess.
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * Checkpoint assigned to the hostess.
     */
    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(Checkpoint::class);
    }
}
