<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

class Event extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'organizer_user_id',
        'code',
        'name',
        'description',
        'start_at',
        'end_at',
        'timezone',
        'status',
        'capacity',
        'checkin_policy',
        'settings_json',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'settings_json' => 'array',
        'capacity' => 'integer',
    ];

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::saving(function (Event $event): void {
            if ($event->start_at && $event->end_at && $event->start_at->gte($event->end_at)) {
                throw new InvalidArgumentException('The event end time must be after the start time.');
            }

            if ($event->organizer_user_id && $event->tenant_id) {
                $organizerTenantId = User::withTrashed()
                    ->whereKey($event->organizer_user_id)
                    ->value('tenant_id');

                if ($organizerTenantId === null) {
                    throw new InvalidArgumentException('The organizer user must exist.');
                }

                if ($organizerTenantId !== $event->tenant_id) {
                    throw new InvalidArgumentException('The organizer must belong to the same tenant as the event.');
                }
            }
        });

        static::deleting(function (Event $event): void {
            $relations = [
                'guestLists',
                'guests',
                'tickets',
                'attendances',
                'imports',
            ];

            foreach ($relations as $relation) {
                if ($event->isForceDeleting()) {
                    $event->{$relation}()->withTrashed()->get()->each->forceDelete();

                    continue;
                }

                $event->{$relation}()->get()->each->delete();
            }
        });
    }

    /**
     * Scope the query to events that belong to the given tenant.
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Tenant that owns the event.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Organizer responsible for the event.
     */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_user_id');
    }

    /**
     * Venues assigned to the event.
     */
    public function venues(): HasMany
    {
        return $this->hasMany(Venue::class);
    }

    /**
     * Checkpoints defined for the event.
     */
    public function checkpoints(): HasMany
    {
        return $this->hasMany(Checkpoint::class);
    }

    /**
     * Guest lists configured for the event.
     */
    public function guestLists(): HasMany
    {
        return $this->hasMany(GuestList::class);
    }

    /**
     * Guests that belong to the event.
     */
    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class);
    }

    /**
     * Tickets emitted for the event.
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Attendance records captured for the event.
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Imports executed for the event.
     */
    public function imports(): HasMany
    {
        return $this->hasMany(Import::class);
    }
}
