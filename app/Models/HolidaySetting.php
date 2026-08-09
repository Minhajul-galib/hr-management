<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HolidaySetting extends Model
{
    protected $fillable = ['all_fridays', 'weekday_off'];

    protected $casts = [
        'all_fridays' => 'boolean',
        'weekday_off' => 'array',
    ];

    public static function getCurrent(): self
    {
        return static::query()->first() ?? new static(['all_fridays' => false, 'weekday_off' => []]);
    }
}
