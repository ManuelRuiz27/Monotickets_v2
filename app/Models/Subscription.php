<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_TRIALING = 'trialing';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_CANCELED = 'canceled';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'plan_id',
        'status',
        'current_period_start',
        'current_period_end',
        'cancel_at_period_end',
        'trial_end',
        'meta_json',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'current_period_start' => 'immutable_datetime',
        'current_period_end' => 'immutable_datetime',
        'cancel_at_period_end' => 'boolean',
        'trial_end' => 'immutable_datetime',
        'meta_json' => 'array',
    ];

    /**
     * Tenant associated with the subscription.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Plan associated with the subscription.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Scope the query to active or trialing subscriptions.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_TRIALING,
            self::STATUS_ACTIVE,
        ]);
    }
}
