<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Designation extends Model
{
    protected $fillable = ['code', 'title', 'serial', 'is_active'];

    protected $casts = [
        'serial' => 'integer',
        'is_active' => 'boolean',
    ];

    public function acrTemplates(): BelongsToMany
    {
        return $this->belongsToMany(AcrTemplate::class, 'acr_template_designation');
    }
}
