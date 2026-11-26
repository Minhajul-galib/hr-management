<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pay grade: a code (the grade number, such as 400), its type (the Group companies or VBL, and
 * male employees or female employees and those aged 65 or over; the latter will decide tax) and
 * the salary formula it is paid by.
 *
 * It carries no pay of its own. Its salary (Basic, Gross, House Rent, ...) comes from its
 * formula's components (see PayScale), so changing a formula changes every grade paid by it.
 */
class Grade extends Model
{
    use SoftDeletes;

    public const GROUP_MALE = 'group_male';

    public const GROUP_FEMALE_65 = 'group_female_65';

    public const VBL_MALE = 'vbl_male';

    public const VBL_FEMALE_65 = 'vbl_female_65';

    public const TYPES = [self::GROUP_MALE, self::GROUP_FEMALE_65, self::VBL_MALE, self::VBL_FEMALE_65];

    protected $fillable = ['code', 'type', 'pay_scale_id', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** The salary formula this grade is paid by. */
    public function payScale(): BelongsTo
    {
        return $this->belongsTo(PayScale::class);
    }

    /**
     * This grade's salary: each component of its formula (Basic, Gross, ...) in order, with the
     * amount for this grade (null when it cannot be worked out, e.g. the grade is not a number).
     *
     * @return list<array{title: string, value: float|null}>
     */
    public function calculations(): array
    {
        $scale = $this->payScale;
        if (! $scale) {
            return [];
        }

        $values = $scale->calculate($this->code);

        return $scale->lines->map(fn (PayScaleLine $line) => ['title' => $line->title, 'value' => $values[$line->id] ?? null])->all();
    }
}
