<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Audit extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_COMPLETED = 'completed';

    // status / completed_* are workflow fields and are only ever set with forceFill().
    protected $fillable = [
        'unit_id',
        'facility_name',
        'facility_address',
        'number_of_employees',
        'assessment_date',
        'assessment_standard',
        'assessment_partner',
        'audit_type',
        'auditors',
        'factory_representatives',
        'established_year',
        'total_area',
        'warehouse_area',
        'production_area',
        'office_area',
        'recent_buyer',
        'monthly_capacity',
        'total_lines',
        'total_machines',
        'manpower',
        'building_storeys',
        'floor_layout',
        'legal_documents',
        'fire_equipment',
        'overall_result',
        'result_comments',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'assessment_date' => 'date:Y-m-d',
            'auditors' => 'array',
            'factory_representatives' => 'array',
            'manpower' => 'array',
            'floor_layout' => 'array',
            'legal_documents' => 'array',
            'fire_equipment' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Audit $audit) {
            $audit->forceFill([
                'reference_no' => sprintf('IA-%s-%04d', $audit->assessment_date->format('Y'), $audit->id),
            ])->saveQuietly();
        });
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(AuditFinding::class);
    }
}
