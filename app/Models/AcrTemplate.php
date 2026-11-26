<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AcrTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = ['code', 'name', 'criteria', 'rating_scale', 'is_active'];

    protected $casts = [
        'criteria' => 'array',
        'rating_scale' => 'array',
        'is_active' => 'boolean',
    ];

    /** Designations this template applies to. */
    public function designations(): BelongsToMany
    {
        return $this->belongsToMany(Designation::class, 'acr_template_designation');
    }
}
