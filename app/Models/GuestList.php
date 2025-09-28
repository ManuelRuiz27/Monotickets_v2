<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class GuestList extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'event_id',
        'name',
        'description',
        'criteria_json',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'criteria_json' => 'array',
    ];

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::deleting(function (GuestList $guestList): void {
            if ($guestList->isForceDeleting()) {
                $guestList->guests()
                    ->withTrashed()
                    ->get()
                    ->each
                    ->forceDelete();

                return;
            }

            $guestList->guests()->get()->each->delete();
        });
    }

    /**
     * Event that owns the guest list.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Guests included in the list.
     */
    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class);
    }
}
