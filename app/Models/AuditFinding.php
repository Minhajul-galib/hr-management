<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditFinding extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'category',
        'rating',
        'finding',
        'corrective_action',
        'responsible_person',
        'target_date',
        'status',
        'closed_by',
        'closed_at',
        'sort_order',
    ];

    protected $appends = ['is_overdue'];

    protected function casts(): array
    {
        return [
            'target_date' => 'date:Y-m-d',
            'closed_at' => 'datetime',
        ];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function requiresCap(): bool
    {
        return $this->rating !== 'compliant';
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === self::STATUS_OPEN
            && $this->target_date !== null
            && $this->target_date->lt(today());
    }
}
