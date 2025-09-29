<?php

namespace App\Models;

use App\Models\Scopes\EventTenantScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Guest extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'event_id',
        'tenant_id',
        'guest_list_id',
        'full_name',
        'email',
        'phone',
        'organization',
        'rsvp_status',
        'rsvp_at',
        'allow_plus_ones',
        'plus_ones_limit',
        'custom_fields_json',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'rsvp_at' => 'datetime',
        'allow_plus_ones' => 'boolean',
        'plus_ones_limit' => 'integer',
        'custom_fields_json' => 'array',
    ];

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new EventTenantScope());

        static::creating(function (Guest $guest): void {
            if ($guest->tenant_id === null && $guest->event_id !== null) {
                $tenantId = Event::query()
                    ->whereKey($guest->event_id)
                    ->value('tenant_id');

                if ($tenantId !== null) {
                    $guest->tenant_id = $tenantId;
                }
            }
        });

        static::deleting(function (Guest $guest): void {
            if ($guest->isForceDeleting()) {
                $guest->tickets()->withTrashed()->get()->each->forceDelete();
                $guest->attendances()->withTrashed()->get()->each->forceDelete();

                return;
            }

            $guest->tickets()->get()->each->delete();
            $guest->attendances()->get()->each->delete();
        });
    }

    /**
     * Event the guest is linked to.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Guest list that the guest belongs to.
     */
    public function guestList(): BelongsTo
    {
        return $this->belongsTo(GuestList::class);
    }

    /**
     * Tickets owned by the guest.
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Attendance records captured for the guest.
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Tenant associated with the guest.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
