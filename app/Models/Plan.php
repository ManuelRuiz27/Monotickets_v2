<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'code',
        'name',
        'price_cents',
        'billing_cycle',
        'limits_json',
        'features_json',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'price_cents' => 'integer',
        'limits_json' => 'array',
        'features_json' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Subscriptions that reference the plan.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
