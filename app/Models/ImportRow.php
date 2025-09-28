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

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'import_id',
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
}
