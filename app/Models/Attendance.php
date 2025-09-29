<?php

namespace App\Models;

use App\Models\Scopes\EventTenantScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Attendance extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope(new EventTenantScope());

        static::creating(function (Attendance $attendance): void {
            if ($attendance->tenant_id !== null) {
                return;
            }

            if ($attendance->event_id !== null) {
                $tenantId = DB::table('events')
                    ->where('id', $attendance->event_id)
                    ->value('tenant_id');

                if ($tenantId !== null) {
                    $attendance->tenant_id = $tenantId;
                }
            }
        });
    }

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'event_id',
        'tenant_id',
        'ticket_id',
        'guest_id',
        'checkpoint_id',
        'hostess_user_id',
        'result',
        'scanned_at',
        'device_id',
        'offline',
        'metadata_json',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'scanned_at' => 'datetime',
        'offline' => 'boolean',
        'metadata_json' => 'array',
    ];

    /**
     * Event associated with the attendance.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Ticket scanned in the attendance.
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Guest detected during the scan.
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * Tenant that owns the attendance.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Checkpoint where the scan occurred.
     */
    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(Checkpoint::class);
    }

    /**
     * Hostess user responsible for the scan.
     */
    public function hostess(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hostess_user_id');
    }
}
