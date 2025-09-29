<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ImportRow extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (ImportRow $row): void {
            if ($row->tenant_id === null && $row->import_id !== null) {
                $tenantId = Import::query()
                    ->whereKey($row->import_id)
                    ->value('tenant_id');

                if ($tenantId !== null) {
                    $row->tenant_id = $tenantId;
                }
            }
        });
    }

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'import_id',
        'tenant_id',
        'row_num',
        'data_json',
        'status',
        'error_msg',
        'entity_id_created',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'row_num' => 'integer',
        'data_json' => 'array',
    ];

    /**
     * Import that generated the row.
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    /**
     * Tenant associated with the import row.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
