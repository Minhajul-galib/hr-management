<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A salary head paid on top of basic pay (house rent, medical, conveyance, ...).
 * Its rate is set here once; every grade that uses it gets the amount worked
 * out from that grade's basic salary.
 */
class SalaryType extends Model
{
    /** The value is a percentage of the basic salary. */
    public const PERCENT_OF_BASIC = 'percent_of_basic';

    /** The value is an amount paid as it is. */
    public const FIXED = 'fixed';

    protected $fillable = ['code', 'name', 'calculation', 'value', 'description', 'is_active'];

    protected $casts = [
        'value' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function grades(): BelongsToMany
    {
        return $this->belongsToMany(Grade::class, 'grade_salary_type');
    }

    /** What this head pays on a given basic salary, rounded to 2 decimals. */
    public function amountFor(string|float|int|null $basic): float
    {
        if ($this->calculation === self::PERCENT_OF_BASIC) {
            return round((float) $basic * (float) $this->value / 100, 2);
        }

        return round((float) $this->value, 2);
    }
}
