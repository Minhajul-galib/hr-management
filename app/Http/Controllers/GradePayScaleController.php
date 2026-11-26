<?php

namespace App\Http\Controllers;

use App\Models\PayScale;
use App\Models\PayScaleLine;
use App\Support\Payroll\Formula;
use App\Support\Payroll\PayScaleCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The salary formulas on the Grades & Pay Scales page (see PayScale): named sets of components,
 * each a title (Basic, Gross, House Rent, ...) and a formula the user types. Every grade picks the
 * formula it is paid by, so formulas can be added, renamed, changed, and deleted while no grade
 * uses them. While editing, the page asks preview() what the unsaved formulas give for a grade.
 */
class GradePayScaleController extends Controller
{
    /** The grade the cards show example amounts for. */
    public const EXAMPLE_GRADE = 400;

    private const MAX_LINES = 30;

    /**
     * Every formula with its components and how many grades use it, for the page.
     *
     * @return array<string, mixed>
     */
    public static function overview(): array
    {
        $scales = PayScale::with('lines')->withCount('grades')->orderBy('id')->get();

        $formulas = $scales->map(function (PayScale $scale) {
            $example = $scale->calculate(self::EXAMPLE_GRADE);

            return [
                'id' => $scale->id,
                'name' => $scale->name,
                'grades_count' => $scale->grades_count,
                'lines' => $scale->lines->map(fn (PayScaleLine $line) => [
                    'key' => 'l'.$line->id,
                    'id' => $line->id,
                    'title' => $line->title,
                    'formula' => $line->formula,
                    'example' => $example[$line->id] ?? null,
                ])->values()->all(),
            ];
        })->all();

        // The grades table has one column per title, whichever formula uses it (matched ignoring case).
        $titles = collect($formulas)->flatMap(fn (array $f) => array_column($f['lines'], 'title'))
            ->unique(fn (string $title) => Formula::key($title))
            ->map(fn (string $title) => ['key' => Formula::key($title), 'label' => $title])
            ->values()->all();

        return [
            'formulas' => $formulas,
            'titles' => $titles,
            'example_grade' => self::EXAMPLE_GRADE,
        ];
    }

    /** What unsaved formulas give for one grade, and what is wrong with them. Nothing is saved. */
    public function preview(Request $request): JsonResponse
    {
        $rows = $this->rows($request, existing: null);
        $grade = mb_substr(trim((string) $request->input('grade')), 0, 20);

        $scale = new PayScale;
        $scale->setRelation('lines', collect($rows)->values()->map(function (array $row, int $i) {
            $line = new PayScaleLine(['title' => (string) $row['title'], 'formula' => (string) $row['formula']]);
            $line->id = $i + 1;

            return $line;
        }));
        $values = $scale->calculate($grade);

        return response()->json([
            'values' => collect($rows)->values()->mapWithKeys(fn (array $row, int $i) => [$row['key'] => $values[$i + 1] ?? null]),
            'errors' => (object) PayScaleCheck::errors($rows),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $name = $this->name($request, null);
        $rows = $this->rows($request, existing: [], check: true);

        DB::transaction(fn () => $this->save(PayScale::create(['name' => $name]), $rows));

        return redirect()->route('grades.index')->with('success', "Formula \"{$name}\" added. Grades can now be given it.");
    }

    public function update(Request $request, PayScale $payScale): RedirectResponse
    {
        $name = $this->name($request, $payScale->id);
        $rows = $this->rows($request, existing: $payScale->lines()->pluck('id')->all(), check: true);

        DB::transaction(function () use ($payScale, $name, $rows) {
            $payScale->update(['name' => $name]);
            $this->save($payScale, $rows);
        });

        return redirect()->route('grades.index')->with('success', "Formula \"{$name}\" updated.");
    }

    public function destroy(PayScale $payScale): RedirectResponse
    {
        $count = $payScale->grades()->withTrashed()->count(); // a grade in the Trash is still paid by it
        if ($count > 0) {
            $inTrash = $count - $payScale->grades()->count();
            $note = $inTrash > 0 ? " ({$inTrash} of them in the Trash)" : '';

            return redirect()->route('grades.index')->with('error', "The formula \"{$payScale->name}\" cannot be deleted because {$count} grade(s) are paid by it{$note}. Give them another formula first.");
        }

        $payScale->delete(); // its components go with it

        return redirect()->route('grades.index')->with('success', "Formula \"{$payScale->name}\" deleted.");
    }

    /** @param list<array<string, mixed>> $rows */
    private function save(PayScale $scale, array $rows): void
    {
        $scale->lines()->whereNotIn('id', array_filter(array_column($rows, 'id')))->delete();

        foreach (array_values($rows) as $position => $row) {
            $line = ! empty($row['id']) ? $scale->lines()->whereKey($row['id'])->firstOrFail() : new PayScaleLine(['pay_scale_id' => $scale->id]);

            $line->fill([
                'position' => $position,
                'title' => trim(preg_replace('/\s+/u', ' ', $row['title'])),
                'formula' => trim($row['formula']),
            ])->save();
        }
    }

    /** The formula's name: needed, and not another formula's (ignoring case). */
    private function name(Request $request, ?int $ignore): string
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                function (string $attribute, mixed $value, \Closure $fail) use ($ignore) {
                    $taken = is_string($value) && PayScale::query()
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($value))])
                        ->when($ignore, fn ($q) => $q->whereKeyNot($ignore))
                        ->exists();
                    if ($taken) {
                        $fail('Another formula already has this name.');
                    }
                },
            ],
        ], [
            'name.required' => 'Give the formula a name, e.g. Group.',
        ]);

        return trim(preg_replace('/\s+/u', ' ', $data['name']));
    }

    /**
     * The components sent: [{key, id, title, formula}]. With `check`, their titles and formulas
     * must also be right (PayScaleCheck); a preview reports those problems instead of refusing.
     *
     * @param  list<int>|null  $existing  ids of the formula's own components (null: ids are not used)
     * @return list<array<string, mixed>>
     */
    private function rows(Request $request, ?array $existing, bool $check = false): array
    {
        $validator = Validator::make($request->all(), [
            'lines' => ['nullable', 'array', 'max:'.self::MAX_LINES],
            'lines.*.key' => ['required', 'string', 'max:40', 'distinct'],
            'lines.*.id' => $existing === null ? ['nullable'] : ['nullable', 'integer', Rule::in($existing)],
            'lines.*.title' => [$check ? 'required' : 'nullable', 'string', 'max:100'],
            'lines.*.formula' => [$check ? 'required' : 'nullable', 'string', 'max:'.Formula::MAX_LENGTH],
        ], [
            'lines.*.title.required' => 'Give the component a title, e.g. Basic.',
            'lines.*.formula.required' => 'Enter a formula, e.g. [Grade] * 25 + 950 or 2000.',
            'lines.max' => 'A formula can have at most '.self::MAX_LINES.' components.',
        ]);

        if ($check) {
            $validator->after(function ($v) use ($request) {
                if ($v->errors()->isEmpty()) {
                    foreach (PayScaleCheck::errors(array_values((array) $request->input('lines', []))) as $field => $message) {
                        $v->errors()->add($field, $message);
                    }
                }
            });
        }

        return array_map(fn (array $row) => $row + ['title' => '', 'formula' => '', 'id' => null], array_values($validator->validate()['lines'] ?? []));
    }
}
