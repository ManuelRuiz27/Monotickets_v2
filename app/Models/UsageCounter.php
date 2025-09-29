<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageCounter extends Model
{
    use HasFactory;
    use HasUuids;

    public const KEY_EVENT_COUNT = 'event_count';
    public const KEY_USER_COUNT = 'user_count';
    public const KEY_SCAN_COUNT = 'scan_count';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'event_id',
        'key',
        'value',
        'period_start',
        'period_end',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'value' => 'integer',
        'period_start' => 'immutable_datetime',
        'period_end' => 'immutable_datetime',
    ];

    /**
     * Tenant associated with the counter.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Event associated with the counter (for scan counters).
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
