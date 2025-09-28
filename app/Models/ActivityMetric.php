<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hourly aggregation of engagement metrics for an event.
 */
class ActivityMetric extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'event_id',
        'date_hour',
        'invites_sent',
        'rsvp_confirmed',
        'scans_valid',
        'scans_duplicate',
        'unique_guests_in',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'date_hour' => 'datetime',
        'invites_sent' => 'integer',
        'rsvp_confirmed' => 'integer',
        'scans_valid' => 'integer',
        'scans_duplicate' => 'integer',
        'unique_guests_in' => 'integer',
    ];

    /**
     * Event that owns the activity metrics bucket.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
