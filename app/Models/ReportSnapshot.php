<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cached report results for expensive or parametrized calculations.
 */
class ReportSnapshot extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'event_id',
        'type',
        'params_json',
        'params_hash',
        'result_json',
        'computed_at',
        'ttl_seconds',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'params_json' => 'array',
        'result_json' => 'array',
        'computed_at' => 'datetime',
        'ttl_seconds' => 'integer',
    ];

    /**
     * Determine if the snapshot has expired based on its TTL.
     */
    public function hasExpired(): bool
    {
        if ($this->computed_at === null || $this->ttl_seconds === null) {
            return false;
        }

        return $this->computed_at->copy()->addSeconds($this->ttl_seconds)->isPast();
    }

    /**
     * Tenant that owns the cached report.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Event associated with the report snapshot.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
