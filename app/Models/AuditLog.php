<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasFactory;
    use HasUlids;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'entity',
        'entity_id',
        'action',
        'diff_json',
        'ip',
        'ua',
        'occurred_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'diff_json' => 'array',
        'occurred_at' => 'datetime',
    ];

    /**
     * Tenant related to the audit log.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * User associated with the audit log entry.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
