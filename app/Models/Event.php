<?php

namespace App\Models;

use App\Models\Attendance;
use App\Models\Scopes\TenantScope;
use App\Models\Ticket;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        static::addGlobalScope(new TenantScope());

        static::creating(function (Event $event): void {
            $context = app(TenantContext::class);

            if ($context->hasTenant() && empty($event->tenant_id)) {
                $event->tenant_id = $context->tenantId();
            }
        });

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
     * Scope the query to include occupancy metrics without incurring N+1 queries.
     */
    public function scopeWithOccupancyMetrics(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('events.*');
        }

        $attendanceMetrics = Attendance::query()
            ->select([
                'event_id',
                DB::raw('count(*) as valid_attendances'),
                DB::raw('count(distinct ticket_id) as unique_tickets'),
            ])
            ->where('result', 'valid')
            ->whereNull('deleted_at')
            ->groupBy('event_id');

        $ticketMetrics = Ticket::query()
            ->select([
                'event_id',
                DB::raw('count(*) as issued_tickets'),
            ])
            ->whereNull('deleted_at')
            ->groupBy('event_id');

        return $query
            ->leftJoinSub($attendanceMetrics, 'attendance_metrics', function ($join): void {
                $join->on('attendance_metrics.event_id', '=', 'events.id');
            })
            ->leftJoinSub($ticketMetrics, 'ticket_metrics', function ($join): void {
                $join->on('ticket_metrics.event_id', '=', 'events.id');
            })
            ->addSelect([
                DB::raw('coalesce(attendance_metrics.valid_attendances, 0) as attendances_count'),
                DB::raw('coalesce(attendance_metrics.unique_tickets, 0) as capacity_used'),
                DB::raw('coalesce(ticket_metrics.issued_tickets, 0) as tickets_issued'),
            ]);
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

    /**
     * Determine if the event should be treated as running in live mode.
     */
    public function isLiveMode(): bool
    {
        $settings = $this->settings_json;

        if (is_array($settings)) {
            $mode = Arr::get($settings, 'mode');

            if (is_string($mode)) {
                $normalised = (string) Str::of($mode)->lower()->replace(' ', '_');

                if (in_array($normalised, ['live', 'en_vivo', 'envivo'], true)) {
                    return true;
                }
            }

            $liveFlag = Arr::get($settings, 'live_mode');

            if (is_bool($liveFlag)) {
                return $liveFlag;
            }

            if (is_string($liveFlag)) {
                $normalised = Str::of($liveFlag)->lower()->trim();

                if (in_array($normalised, ['1', 'true', 'on', 'yes', 'live'], true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
