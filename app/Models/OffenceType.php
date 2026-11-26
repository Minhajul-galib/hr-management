<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OffenceType extends Model
{
    use SoftDeletes;

    protected $fillable = ['code', 'name', 'severity', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
