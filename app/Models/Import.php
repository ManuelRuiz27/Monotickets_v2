<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Import extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'event_id',
        'source',
        'status',
        'rows_total',
        'rows_ok',
        'rows_failed',
        'report_file_url',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'rows_total' => 'integer',
        'rows_ok' => 'integer',
        'rows_failed' => 'integer',
    ];

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::deleting(function (Import $import): void {
            if ($import->isForceDeleting()) {
                $import->rows()->withTrashed()->get()->each->forceDelete();

                return;
            }

            $import->rows()->get()->each->delete();
        });
    }

    /**
     * Tenant that owns the import.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Event targeted by the import.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Rows processed within the import.
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }
}
