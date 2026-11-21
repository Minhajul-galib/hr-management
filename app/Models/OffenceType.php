<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OffenceType extends Model
{
    protected $fillable = ['code', 'name', 'severity', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
