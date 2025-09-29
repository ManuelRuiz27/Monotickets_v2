<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class Tenant extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'status',
        'plan',
        'settings_json',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'settings_json' => 'array',
    ];

    /**
     * Get users that belong to the tenant.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get roles defined for the tenant.
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /**
     * Audit logs for this tenant.
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Subscriptions owned by the tenant.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Retrieve the most recent active subscription for the tenant.
     */
    public function activeSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->active()
            ->with('plan')
            ->orderByDesc('current_period_end')
            ->first();
    }

    /**
     * Accessor for the tenant branding settings.
     *
     * @return array{logo_url: ?string, colors: array{primary: ?string, accent: ?string, bg: ?string, text: ?string}, email_from: ?string, email_reply_to: ?string}
     */
    public function branding(): array
    {
        $settings = $this->settings_json;

        $branding = is_array($settings) ? Arr::get($settings, 'branding', []) : [];
        $colors = is_array($branding) ? Arr::get($branding, 'colors', []) : [];

        return [
            'logo_url' => $this->stringOrNull(Arr::get($branding, 'logo_url')),
            'colors' => [
                'primary' => $this->stringOrNull(Arr::get($colors, 'primary')),
                'accent' => $this->stringOrNull(Arr::get($colors, 'accent')),
                'bg' => $this->stringOrNull(Arr::get($colors, 'bg')),
                'text' => $this->stringOrNull(Arr::get($colors, 'text')),
            ],
            'email_from' => $this->stringOrNull(Arr::get($branding, 'email_from')),
            'email_reply_to' => $this->stringOrNull(Arr::get($branding, 'email_reply_to')),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
