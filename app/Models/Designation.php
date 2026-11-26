<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A job title. It belongs to a category (see DesignationCategory) and its serial is its place on
 * that category's own ladder: a lower serial is more senior, and each category counts from 1.
 */
class Designation extends Model
{
    use SoftDeletes;

    protected $fillable = ['designation_category_id', 'code', 'title', 'serial', 'is_active'];

    protected $casts = [
        'serial' => 'integer',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(DesignationCategory::class, 'designation_category_id');
    }

    public function acrTemplates(): BelongsToMany
    {
        return $this->belongsToMany(AcrTemplate::class, 'acr_template_designation');
    }
}
