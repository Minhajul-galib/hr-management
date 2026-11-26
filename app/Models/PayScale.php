<?php

namespace App\Models;

use App\Support\Payroll\Formula;
use App\Support\Payroll\FormulaException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A salary formula: a named set of components (see PayScaleLine), each a title and a formula the
 * user can change, that works a grade's salary out from its number. Every grade says which
 * formula it is paid by, and the user can add more, so grades can be paid differently.
 *
 *  - Group: Basic = [Grade] * 25 + 950, House Rent = [Basic] / 2, Conveyance = 2000,
 *           Gross = [Basic] + [House Rent] + [Conveyance]
 *  - VBL:   Gross = [Grade] * 25 + 950, Basic = [Gross] * 60%, House Rent = [Gross] * 30%, ...
 *
 * Components may use one another in any order (VBL starts from Gross); they are listed in the
 * order the user put them.
 */
class PayScale extends Model
{
    protected $fillable = ['name'];

    /** In the order the user put them. */
    public function lines(): HasMany
    {
        return $this->hasMany(PayScaleLine::class)->orderBy('position')->orderBy('id');
    }

    /** The grades paid by this formula. */
    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    /** Whether any formula uses [Grade]: the grade code then has to be a plain number. */
    public function usesGradeNumber(): bool
    {
        return $this->lines->contains(function (PayScaleLine $line) {
            try {
                return in_array(Formula::GRADE, Formula::parse($line->formula)->references(), true);
            } catch (FormulaException) {
                return false;
            }
        });
    }

    /**
     * Every component worked out for a grade, by line id, in the order shown. A value is null
     * when it cannot be worked out: the grade is not a plain number (a code like "g1" has
     * nothing to multiply), a component it uses is null, or it divides by zero. Saved formulas
     * are checked (no unknown names, no loops) before they are stored, but a loop is still
     * caught here rather than looping forever.
     *
     * @return array<int, float|null>
     */
    public function calculate(string|int|null $grade): array
    {
        $grade = (string) $grade;
        $number = $grade !== '' && ctype_digit($grade) ? (float) $grade : null;

        $byKey = $this->lines->keyBy(fn (PayScaleLine $line) => $line->key());
        $values = [];
        $working = [];

        $value = function (PayScaleLine $line) use (&$value, &$values, &$working, $byKey, $number): ?float {
            if (array_key_exists($line->id, $values)) {
                return $values[$line->id];
            }
            if (isset($working[$line->id])) {
                return null; // uses itself, directly or through others
            }
            $working[$line->id] = true;

            try {
                $result = Formula::parse($line->formula)->evaluate(
                    fn (string $name) => $name === Formula::GRADE ? $number : ($byKey->has($name) ? $value($byKey->get($name)) : null),
                );
            } catch (FormulaException) {
                $result = null;
            }

            unset($working[$line->id]);

            return $values[$line->id] = $result === null ? null : round($result, 2);
        };

        $ordered = [];
        foreach ($this->lines as $line) {
            $ordered[$line->id] = $value($line);
        }

        return $ordered;
    }
}
