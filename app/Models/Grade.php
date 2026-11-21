<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Grade extends Model
{
    protected $fillable = ['code', 'name', 'basic_min', 'basic_max', 'is_active'];

    protected $casts = [
        'basic_min' => 'decimal:2',
        'basic_max' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /** The salary heads paid on top of basic in this grade. */
    public function salaryTypes(): BelongsToMany
    {
        return $this->belongsToMany(SalaryType::class, 'grade_salary_type');
    }

    /**
     * Basic plus every active salary type at the given basic. An inactive
     * salary type is one that is no longer paid, so it does not count.
     */
    public function grossAt(string|float|int|null $basic): float
    {
        $extra = $this->salaryTypes
            ->where('is_active', true)
            ->sum(fn (SalaryType $type) => $type->amountFor($basic));

        return round((float) $basic + $extra, 2);
    }
}
