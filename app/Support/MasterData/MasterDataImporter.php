<?php

namespace App\Support\MasterData;

use App\Exports\MasterDataExport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Turns the rows of an uploaded sheet into records of one master.
 *
 * Importing has two steps. check() works out, row by row, what an import
 * would do — add, update, leave unchanged, skip, or refuse and why — without
 * saving anything; the page shows that as an editable grid so problems can be
 * fixed before anything is stored. commit() then saves the rows that pass the
 * very same checks. import() does both in one go, straight from a sheet.
 *
 * Each row is matched on the master's `import.match` field(s) (the code): an
 * unknown code adds a record, a known one updates it. Every row goes through
 * the same validation rules as the form, so a sheet can't store anything the
 * screen wouldn't; on top of that a code (and any `unique` column set) may
 * appear only once per file. Where a code clashes or is missing, a free code
 * from the same series is suggested (see CodeSuggester).
 *
 * Only scalar fields are importable (text, textarea, number, boolean, selects
 * with fixed options, and selects that pick another master, which take that
 * master's code). A blank optional cell leaves an existing record's value as
 * it is, and gives a new record the field's default.
 *
 * The template's sample rows are skipped while they are left unchanged — a
 * row of the file that is exactly a sample, for a code that isn't saved yet.
 * Rows sent back from the review screen say themselves whether they are
 * samples (`sample`), so a row typed there is never mistaken for one.
 *
 * It works on plain arrays (the reader hands it every row, first sheet row
 * first), so the rules can be tested without any file.
 */
final class MasterDataImporter
{
    public const MAX_ROWS = 2000;

    private const HEADING_SEARCH_ROWS = 10;

    private const IMPORTABLE = ['text', 'textarea', 'number', 'boolean', 'select', 'multiselect'];

    private const YES = ['yes', 'y', 'true', '1', 'active'];

    private const NO = ['no', 'n', 'false', '0', 'inactive'];

    /** @var list<array<string, mixed>> */
    private readonly array $columns;

    /** @var array<string, array<string, mixed>> */
    private readonly array $fields;

    /** @var list<string> */
    private readonly array $match;

    /** The match field that holds a code, when there is one. */
    private readonly ?string $codeKey;

    /** @var array<string, list<array{id: int, code: string, label: string, active: bool}>> lookup field => targets */
    private array $targets = [];

    private ?CodeSuggester $suggester = null;

    /** @param  array<string, mixed>  $definition  A MasterDataRegistry entry with an `import` section. */
    public function __construct(private readonly array $definition)
    {
        $this->columns = self::columns($definition);
        $this->fields = array_column($this->columns, null, 'key');
        $this->match = self::matchKeys($definition);
        $this->codeKey = in_array('code', $this->match, true) ? 'code' : null;
    }

    // ----- description of the sheet ----------------------------------------------

    /**
     * The fields that make up the sheet's columns, in form order.
     *
     * @return list<array<string, mixed>>
     */
    public static function columns(array $definition): array
    {
        return array_values(array_filter(
            $definition['fields'],
            fn (array $f) => in_array($f['type'], self::IMPORTABLE, true)
                && ($f['type'] !== 'select' || isset($f['options']) || isset($f['lookup']))
                && ($f['type'] !== 'multiselect' || isset($f['lookup'])),
        ));
    }

    /**
     * A column's heading: the field label, or — for a field that takes another
     * master's code(s) — "<label> Code" / "<label> Codes", unless the field names its own `heading`.
     */
    public static function heading(array $field): string
    {
        return $field['heading'] ?? match (true) {
            $field['type'] === 'multiselect' => $field['label'].' Codes',
            isset($field['lookup']) => $field['label'].' Code',
            default => $field['label'],
        };
    }

    /** @return list<string> The field(s) that identify a row. */
    public static function matchKeys(array $definition): array
    {
        return array_values((array) $definition['import']['match']);
    }

    /**
     * The records a lookup field can point to, by code.
     *
     * @return list<array{id: int, code: string, label: string, active: bool}>
     */
    public static function lookupTargets(array $field): array
    {
        $spec = $field['lookup'];
        $code = $spec['code'] ?? 'code';

        return $spec['model']::query()->orderBy($code)->get()
            ->map(fn (Model $m) => [
                'id' => (int) $m->getKey(),
                'code' => (string) $m->getAttribute($code),
                'label' => (string) $spec['label']($m),
                'active' => (bool) ($m->getAttribute('is_active') ?? true),
            ])
            ->all();
    }

    // ----- the three entry points -------------------------------------------------

    /**
     * The data rows of a sheet, as cell text keyed by field, for check() or commit().
     * Columns the sheet doesn't have are left out of `cells`.
     *
     * @param  list<list<mixed>>  $rows  Every sheet row, the first sheet row first.
     * @return array{records: list<array{row: int, cells: array<string, string>}>, total: int, fatal: ?string}
     */
    public function read(array $rows): array
    {
        $rows = array_values($rows);
        [$map, $firstDataRow] = $this->locateColumns($rows);

        if ($map === null) {
            $headings = implode(', ', array_map(fn ($f) => '"'.self::heading($f).'"', $this->columns));

            return ['records' => [], 'total' => 0, 'fatal' => "Could not find the column headings. Row 1 must hold the template's headings: {$headings}."];
        }

        $missing = array_filter($this->columns, fn ($f) => ! empty($f['required']) && ! isset($map[$f['key']]));
        if ($missing) {
            $names = implode(', ', array_map(fn ($f) => '"'.self::heading($f).'"', $missing));

            return ['records' => [], 'total' => 0, 'fatal' => "The sheet has no {$names} column. Use the template so every required column is there."];
        }

        $records = [];
        for ($index = $firstDataRow; $index < count($rows); $index++) {
            $cells = [];
            foreach ($map as $key => $column) {
                $cells[$key] = $this->cleanText($rows[$index][$column] ?? null, $this->fields[$key]['type'] === 'textarea');
            }

            if (implode('', $cells) !== '') {
                $records[] = ['row' => $index + 1, 'cells' => $cells]; // the row number Excel shows
            }
        }

        // Refused before anything is checked or written.
        if (count($records) > self::MAX_ROWS) {
            return ['records' => [], 'total' => count($records), 'fatal' => 'The sheet has more than '.number_format(self::MAX_ROWS).' rows. Split it into smaller files.'];
        }

        return ['records' => $records, 'total' => count($records), 'fatal' => null];
    }

    /**
     * What importing these rows would do, without saving anything.
     *
     * @param  list<array{row: int, cells: array<string, mixed>, sample?: bool}>  $records
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function check(array $records): array
    {
        $rows = array_map(fn (array $item) => $this->present($item), $this->analyse($records));

        $summary = ['total' => count($rows), 'new' => 0, 'update' => 0, 'unchanged' => 0, 'error' => 0, 'sample' => 0, 'warnings' => 0, 'suggestions' => 0];
        foreach ($rows as $row) {
            $summary[$row['status']]++;
            $summary['warnings'] += $row['warnings'] ? 1 : 0;
            $summary['suggestions'] += $row['suggestions'] ? 1 : 0;
        }

        return ['rows' => $rows, 'summary' => $summary];
    }

    /**
     * Save the rows that pass the checks; report the others.
     *
     * @param  list<array{row: int, cells: array<string, mixed>, sample?: bool}>  $records
     * @return array{total: int, created: int, updated: int, unchanged: int, samples: int, errors: list<array{row: int, key: ?string, message: string}>, fatal: ?string}
     */
    public function commit(array $records): array
    {
        $empty = ['total' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'samples' => 0, 'errors' => [], 'fatal' => null];
        $items = $this->analyse($records);
        $result = ['total' => count($items)] + $empty;

        try {
            DB::transaction(function () use ($items, &$result) {
                foreach ($items as $item) {
                    switch ($item['status']) {
                        case 'sample':
                            $result['samples']++;
                            break;
                        case 'unchanged':
                            $result['unchanged']++;
                            break;
                        case 'error':
                            $result['errors'][] = [
                                'row' => $item['row'],
                                'key' => $this->keyText($item),
                                'message' => implode(' ', [...array_values($item['errors']), ...$item['row_errors']]),
                            ];
                            break;
                        default: // new, update
                            $record = $item['record'] ?? new $this->definition['model'];
                            $record->fill($item['attributes'])->save();
                            foreach ($item['relations'] as $key => $ids) {
                                $record->{$this->fields[$key]['relation']}()->sync($ids);
                            }
                            $result[$item['status'] === 'new' ? 'created' : 'updated']++;
                    }
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Someone saved the same code while this import ran; the transaction kept nothing.
            return ['total' => count($items)] + array_merge($empty, [
                'fatal' => 'Another change to the same codes was saved while this import ran, so nothing was imported. Please import again.',
            ]);
        }

        return $result;
    }

    /**
     * Read a sheet and save it in one go.
     *
     * @param  list<list<mixed>>  $rows  Every sheet row, the first sheet row first.
     * @return array{total: int, created: int, updated: int, unchanged: int, samples: int, errors: list<array{row: int, key: ?string, message: string}>, fatal: ?string}
     */
    public function import(array $rows): array
    {
        $read = $this->read($rows);

        if ($read['fatal'] !== null) {
            return ['total' => $read['total'], 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'samples' => 0, 'errors' => [], 'fatal' => $read['fatal']];
        }

        return $this->commit($read['records']);
    }

    // ----- analysis -----------------------------------------------------------------

    /**
     * One entry per non-blank row, with everything check() reports and commit() saves.
     *
     * @return list<array<string, mixed>>
     */
    private function analyse(array $records): array
    {
        $this->suggester = $this->codeKey ? new CodeSuggester($this->definition['model']) : null;

        $items = [];
        foreach (array_slice(array_values($records), 0, self::MAX_ROWS) as $record) {
            $item = $this->parse(is_array($record) ? $record : []);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        $this->attachSaved($items);
        $this->checkKeys($items);
        foreach ($items as &$item) {
            $this->validate($item);
        }
        unset($item);
        $this->checkUnique($items);
        $this->checkLikelyDuplicates($items);
        foreach ($items as &$item) {
            $this->settle($item);
        }
        unset($item);
        $this->suggestCodes($items);

        return $items;
    }

    /** The row's cells cleaned and turned into field values; null for a blank row. */
    private function parse(array $record): ?array
    {
        $raw = is_array($record['cells'] ?? null) ? $record['cells'] : [];

        $item = [
            'row' => max(0, (int) ($record['row'] ?? 0)),
            'cells' => [], 'present' => [], 'values' => [],
            'errors' => [], 'row_errors' => [], 'warnings' => [], 'suggestions' => [],
            'record' => null, 'data' => [], 'attributes' => [], 'relations' => [], 'status' => null,
            'sample' => false, 'sample_guessed' => false,
        ];

        foreach ($this->columns as $field) {
            $key = $field['key'];
            $item['present'][$key] = array_key_exists($key, $raw);
            $item['cells'][$key] = $this->cleanText($raw[$key] ?? null, $field['type'] === 'textarea');
        }

        if (implode('', $item['cells']) === '') {
            return null;
        }

        if (array_key_exists('sample', $record)) {
            $item['sample'] = (bool) $record['sample'];
        } else {
            $item['sample'] = $item['sample_guessed'] = $this->isSample($item);
        }

        foreach ($this->columns as $field) {
            $text = $item['cells'][$field['key']];
            if ($text !== '') {
                $this->parseCell($item, $field, $text);
            }
        }

        return $item;
    }

    /** Reads one filled cell into $item: its value and tidy text, or what is wrong with it. */
    private function parseCell(array &$item, array $field, string $text): void
    {
        $key = $field['key'];
        $label = self::heading($field);
        $short = Str::limit($text, 30);

        if ($field['type'] === 'boolean') {
            $word = strtolower($text);
            if (! in_array($word, [...self::YES, ...self::NO], true)) {
                $item['errors'][$key] = "{$label} must be Yes or No, not \"{$short}\".";

                return;
            }
            $item['values'][$key] = in_array($word, self::YES, true);
            $item['cells'][$key] = $item['values'][$key] ? 'Yes' : 'No';

            return;
        }

        if (isset($field['lookup'])) {
            $this->parseLookupCell($item, $field, $text);

            return;
        }

        if ($field['type'] === 'select') {
            // Accept the stored value or the label people see on screen.
            $option = collect($field['options'])->first(
                fn ($o) => strcasecmp((string) $o['value'], $text) === 0 || strcasecmp($o['label'], $text) === 0,
            );
            if ($option === null) {
                $labels = array_column($field['options'], 'label');
                $item['errors'][$key] = "{$label} \"{$short}\" is not one of: ".implode(', ', $labels).'.';
                $closest = $this->closest($text, array_merge(
                    array_map(fn ($o) => [(string) $o['value'], $o['label']], $field['options']),
                    array_map(fn ($o) => [$o['label'], $o['label']], $field['options']),
                ));
                if ($closest !== null) {
                    $item['suggestions'][$key] = $closest;
                }

                return;
            }
            $item['values'][$key] = $option['value'];
            $item['cells'][$key] = $option['label'];

            return;
        }

        if ($field['type'] === 'number') {
            // The validator explains a bad number; "80,000" is offered back without the commas.
            $item['values'][$key] = $text;
            $plain = str_replace([',', ' '], '', $text);
            if (! is_numeric($text) && is_numeric($plain)) {
                $item['suggestions'][$key] = $plain;
            }

            return;
        }

        $value = match (true) {
            ! empty($field['uppercase']) => strtoupper($text),
            ! empty($field['lowercase']) => strtolower($text),
            default => $text,
        };
        $item['values'][$key] = $value;
        $item['cells'][$key] = $value;
    }

    /**
     * A cell holding the code of another master's record (a select), or a
     * comma-separated list of such codes (a multiselect). Codes are matched
     * ignoring case; unknown ones are reported, with the closest known code
     * offered when one is a near miss.
     */
    private function parseLookupCell(array &$item, array $field, string $text): void
    {
        $key = $field['key'];
        $label = self::heading($field);
        $many = $field['type'] === 'multiselect';
        $page = $field['lookup']['page'] ?? $field['label'];
        $targets = $this->targets($field);

        $parts = $many ? preg_split('/\s*[,;\n]+\s*/', $text, -1, PREG_SPLIT_NO_EMPTY) : [$text];

        $found = [];
        $unknown = [];
        $fixes = [];
        foreach ($parts as $part) {
            $target = collect($targets)->first(
                fn ($t) => strcasecmp($t['code'], $part) === 0 || strcasecmp($t['label'], $part) === 0,
            );
            if ($target !== null) {
                $found[$target['id']] = $target;

                continue;
            }

            $unknown[] = $part;
            $fixes[] = $this->closest($part, array_merge(
                array_map(fn ($t) => [$t['code'], $t['code']], $targets),
                array_map(fn ($t) => [$t['label'], $t['code']], $targets),
            ));
        }

        if ($unknown) {
            $codes = array_column($targets, 'code');
            $names = implode(', ', array_map(fn ($u) => '"'.Str::limit($u, 30).'"', $unknown));
            $item['errors'][$key] = ($codes
                ? "{$label} {$names} ".(count($unknown) === 1 ? 'was' : 'were').' not found. Use codes from the '.$page.' page: '.implode(', ', array_slice($codes, 0, 10)).(count($codes) > 10 ? ', …' : '').'.'
                : "{$label} {$names} ".(count($unknown) === 1 ? 'was' : 'were')." not found. Add them on the {$page} page first.");

            // Offer the list with every unknown code corrected, when each one is a near miss.
            if (! in_array(null, $fixes, true)) {
                $item['suggestions'][$key] = implode(', ', array_unique([...array_column($found, 'code'), ...$fixes]));
            }

            return;
        }

        $item['values'][$key] = $many ? array_map('intval', array_keys($found)) : reset($found)['id'];
        $item['cells'][$key] = implode(', ', array_column($found, 'code'));

        $inactive = array_column(array_filter($found, fn ($t) => ! $t['active']), 'code');
        if ($inactive) {
            $item['warnings'][$key] = implode(', ', $inactive).(count($inactive) === 1 ? ' is' : ' are').' inactive.';
        }
    }

    /** Find the saved record each row refers to, by its match field(s). */
    private function attachSaved(array &$items): void
    {
        $wanted = [];
        foreach ($items as $item) {
            if ($this->matchValue($item) !== null) {
                foreach ($this->match as $field) {
                    $wanted[$field][] = $item['values'][$field];
                }
            }
        }

        if (! $wanted) {
            return;
        }

        $query = $this->definition['model']::query()->with($this->relations());
        foreach ($wanted as $field => $values) {
            $query->whereIn($field, array_values(array_unique($values)));
        }

        $saved = [];
        foreach ($query->get() as $record) {
            $saved[$this->keyOf(fn ($f) => $record->getAttribute($f))] = $record;
        }

        foreach ($items as &$item) {
            if (($key = $this->matchValue($item)) !== null) {
                $item['record'] = $saved[$key] ?? null;
            }
            // A saved code is real data, even when it reads exactly like a sample (e.g. CL, Casual Leave).
            if ($item['sample_guessed'] && $item['record'] !== null) {
                $item['sample'] = false;
            }
        }
    }

    /** The match field(s) must be filled in, and may appear only once per file. */
    private function checkKeys(array &$items): void
    {
        $seen = [];

        foreach ($items as &$item) {
            if ($item['sample']) {
                continue;
            }

            $missing = false;
            foreach ($this->match as $field) {
                if ($item['cells'][$field] === '' && ! isset($item['errors'][$field])) {
                    $item['errors'][$field] = self::heading($this->fields[$field]).' is missing.';
                }
                $missing = $missing || ! array_key_exists($field, $item['values']);
            }
            if ($missing) {
                continue;
            }

            $key = $this->matchValue($item);
            if (isset($seen[$key])) {
                $at = $this->codeKey ?? end($this->match);
                $others = array_filter($this->match, fn ($f) => $f !== $at);
                $within = $others
                    ? ' for '.implode(', ', array_map(fn ($f) => $this->fields[$f]['label'].' '.$item['cells'][$f], $others))
                    : '';
                $item['errors'][$at] = self::heading($this->fields[$at])." {$item['cells'][$at]} is already used{$within} on row {$seen[$key]} of this file.";
            } else {
                $seen[$key] = $item['row'];
            }
        }
    }

    /** The form's own rules, against what the record would hold after the import. */
    private function validate(array &$item): void
    {
        if ($item['sample']) {
            return;
        }

        $record = $item['record'];
        $item['data'] = $item['values'] + ($record ? $this->currentValues($record) : $this->defaults());

        $labels = array_map(fn ($f) => self::heading($f), $this->fields);
        $validator = Validator::make($item['data'], ($this->definition['rules'])($record?->getKey(), $item['data']), MasterDataRegistry::MESSAGES, $labels);

        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $attribute => $messages) {
                $field = explode('.', $attribute)[0];
                if (isset($this->fields[$field])) {
                    $item['errors'][$field] ??= $messages[0]; // a cell's own problem (e.g. not Yes/No) wins
                } else {
                    $item['row_errors'][] = $messages[0];
                }
            }

            return;
        }

        // Links to other records (multiselects) are saved after the record itself.
        $validated = $validator->validated();
        foreach ($this->fields as $key => $field) {
            if ($field['type'] === 'multiselect') {
                $item['relations'][$key] = array_map('intval', $validated[$key] ?? []);
                unset($validated[$key]);
            }
        }

        $item['attributes'] = isset($this->definition['prepare']) ? ($this->definition['prepare'])($validated) : $validated;
    }

    /** `unique` column sets (e.g. a department name) may also appear only once per file. */
    private function checkUnique(array &$items): void
    {
        foreach ($this->definition['unique'] ?? [] as $set) {
            $seen = [];
            $at = end($set);

            foreach ($items as &$item) {
                $values = array_map(fn ($f) => $item['data'][$f] ?? null, $set);
                if ($item['sample'] || in_array(null, $values, true) || in_array('', $values, true)) {
                    continue;
                }

                $key = mb_strtolower(implode("\x1F", $values));
                if (! isset($seen[$key])) {
                    $seen[$key] = $item['row'];

                    continue;
                }

                $others = array_filter($set, fn ($f) => $f !== $at);
                $within = $others ? ' for the same '.implode(', ', array_map(fn ($f) => $this->fields[$f]['label'], $others)) : '';
                $item['errors'][$at] ??= "{$this->fields[$at]['label']} \"".Str::limit((string) $item['data'][$at], 40)."\" is already used{$within} on row {$seen[$key]} of this file.";
            }
            unset($item);
        }
    }

    /**
     * Fields that usually differ per record (`warn_duplicates`, e.g. a short
     * name) are flagged when they repeat in the file or match another saved
     * record. Only a warning: it is still imported.
     */
    private function checkLikelyDuplicates(array &$items): void
    {
        $model = $this->definition['model'];

        foreach ($this->definition['import']['warn_duplicates'] ?? [] as $field) {
            $rows = [];
            foreach ($items as $i => $item) {
                $value = $item['values'][$field] ?? null;
                if (! $item['sample'] && is_string($value) && ! isset($item['errors'][$field])) {
                    $rows[mb_strtolower($value)][] = $i;
                }
            }
            if (! $rows) {
                continue;
            }

            $saved = [];
            foreach (array_chunk(array_keys($rows), 500) as $chunk) {
                $found = $model::query()->whereIn(DB::raw('LOWER('.DB::getQueryGrammar()->wrap($field).')'), $chunk)->get();
                foreach ($found as $record) {
                    $saved[mb_strtolower((string) $record->getAttribute($field))][] = $record;
                }
            }

            $label = $this->fields[$field]['label'];
            $title = $this->definition['title_key'];

            foreach ($rows as $value => $indexes) {
                foreach ($indexes as $i) {
                    $notes = [];

                    $otherRows = array_map(fn ($j) => $items[$j]['row'], array_filter($indexes, fn ($j) => $j !== $i));
                    if ($otherRows) {
                        $notes[] = 'on row '.implode(', ', array_slice($otherRows, 0, 3)).(count($otherRows) > 3 ? ', …' : '');
                    }

                    // Saved records other than the one this row updates.
                    $own = $items[$i]['record']?->getKey();
                    $others = array_slice(array_filter($saved[$value] ?? [], fn ($r) => $r->getKey() !== $own), 0, 3);
                    if ($others) {
                        $notes[] = 'by '.implode(', ', array_map(function (Model $record) use ($title) {
                            $code = $this->codeKey ? $record->getAttribute($this->codeKey).' ' : '';

                            return $code.'"'.Str::limit((string) $record->getAttribute($title), 40).'"';
                        }, $others));
                    }

                    if ($notes) {
                        $items[$i]['warnings'][$field] ??= "{$label} \"{$items[$i]['cells'][$field]}\" is also used ".implode(' and ', $notes).'. Check it is not the same '.mb_strtolower($this->definition['singular']).' twice.';
                    }
                }
            }
        }
    }

    /** Decide the row's outcome. */
    private function settle(array &$item): void
    {
        if ($item['sample']) {
            // Not imported, so its cells' problems don't matter.
            $item['status'] = 'sample';
            $item['errors'] = $item['row_errors'] = $item['warnings'] = $item['suggestions'] = [];

            return;
        }

        if ($item['errors'] || $item['row_errors']) {
            $item['status'] = 'error';

            return;
        }

        $record = $item['record'];
        if ($record === null) {
            $item['status'] = 'new';

            return;
        }

        $probe = (clone $record)->fill($item['attributes']);
        $item['status'] = $probe->isDirty() || $this->changedRelations($item) ? 'update' : 'unchanged';
    }

    /**
     * Offer a free code where the code is missing, malformed or already used
     * in the file, and where a saved code would be renamed (often a code typed
     * for a new record that happens to be taken).
     */
    private function suggestCodes(array &$items): void
    {
        $key = $this->codeKey;
        if ($key === null || $this->suggester === null) {
            return;
        }

        foreach ($items as $item) {
            if (! $item['sample'] && ($scope = $this->scope($item)) !== null && ($code = $item['values'][$key] ?? '') !== '') {
                $this->suggester->claim((string) $code, $scope);
            }
        }

        $series = $this->usualSeries($items);
        $title = $this->definition['title_key'];

        foreach ($items as &$item) {
            $scope = $this->scope($item);
            if ($item['sample'] || $scope === null) {
                continue;
            }

            $code = (string) ($item['values'][$key] ?? '');
            $suggestion = null;

            if (isset($item['errors'][$key])) {
                $suggestion = match (true) {
                    $code === '' => $series !== null ? $this->suggester->next($series, $scope) : null,
                    ! Code::isValid($code) => $this->suggester->free($code, $scope),
                    default => $this->suggester->next($code, $scope),
                };
            } elseif ($item['status'] === 'update' && isset($this->fields[$title], $item['values'][$title])
                && mb_strtolower((string) $item['values'][$title]) !== mb_strtolower((string) $item['record']->getAttribute($title))) {
                $suggestion = $this->suggester->next($code, $scope);
                if ($suggestion !== null) {
                    $item['warnings'][$key] ??= self::heading($this->fields[$key])." {$code} already belongs to \"".Str::limit((string) $item['record']->getAttribute($title), 40)
                        .'", so this row renames it. If this is a new '.mb_strtolower($this->definition['singular'])." use {$suggestion} instead.";
                }
            }

            if ($suggestion !== null) {
                $item['suggestions'][$key] = $suggestion;
            }
        }
    }

    /** What the page gets for one row. */
    private function present(array $item): array
    {
        $record = $item['record'];
        $was = [];
        $fill = [];

        $current = $record ? $this->currentValues($record) : $this->defaults();

        if ($item['status'] === 'update') {
            $dirty = (clone $record)->fill($item['attributes'])->getDirty();
            $linked = $this->changedRelations($item);
            foreach ($this->fields as $key => $field) { // in column order
                if (array_key_exists($key, $dirty) || in_array($key, $linked, true)) {
                    $was[$key] = $this->display($field, $current[$key]) ?: 'none';
                }
            }
        }

        // An empty cell gets the default (new rows) or keeps what is saved (updates).
        if (! $item['sample']) {
            foreach ($this->fields as $key => $field) {
                if ($item['cells'][$key] !== '' || isset($item['errors'][$key])) {
                    continue;
                }
                $text = $this->display($field, $current[$key]);
                if ($text !== '') {
                    $fill[$key] = $text;
                }
            }
        }

        return [
            'row' => $item['row'],
            'status' => $item['status'],
            'sample' => $item['sample'],
            'cells' => $item['cells'],
            'errors' => $item['errors'],
            'row_errors' => $item['row_errors'],
            'warnings' => $item['warnings'],
            'suggestions' => $item['suggestions'],
            'was' => $was,
            'fill' => $fill,
            'fill_kind' => $record ? 'kept' : 'default',
        ];
    }

    // ----- helpers for the analysis ---------------------------------------------------

    /** A row left exactly as one of the template's sample rows (columns the sheet lacks are ignored). */
    private function isSample(array $item): bool
    {
        foreach ($this->definition['import']['examples'] ?? [] as $example) {
            $same = true;
            foreach ($this->fields as $key => $field) {
                if (! $item['present'][$key]) {
                    continue;
                }
                $sample = $this->cleanText(MasterDataExport::cell($field, $example[$key] ?? ''), $field['type'] === 'textarea');
                if ($this->sampleForm($sample) !== $this->sampleForm($item['cells'][$key])) {
                    $same = false;
                    break;
                }
            }
            if ($same) {
                return true;
            }
        }

        return false;
    }

    /** A cell as compared with a sample's: ignoring case and the spacing around list commas. */
    private function sampleForm(string $text): string
    {
        return mb_strtolower(preg_replace('/\s*,\s*/', ',', $text) ?? $text);
    }

    /** The row's match value(s) as one string, or null while any is missing. */
    private function matchValue(array $item): ?string
    {
        foreach ($this->match as $field) {
            if (! array_key_exists($field, $item['values'])) {
                return null;
            }
        }

        return $this->keyOf(fn ($f) => $item['values'][$f]);
    }

    private function keyOf(callable $value): string
    {
        return implode("\x1F", array_map(fn ($f) => mb_strtoupper((string) $value($f)), $this->match));
    }

    /** How a row is named in the import report: its code (and type, for grades). */
    private function keyText(array $item): ?string
    {
        $text = implode(' / ', array_filter(array_map(fn ($f) => $item['cells'][$f], $this->match), fn ($v) => $v !== ''));

        return $text !== '' ? $text : null;
    }

    /** The other match fields a code is unique within, e.g. the grade type; null when one is unknown. */
    private function scope(array $item): ?array
    {
        $scope = [];
        foreach ($this->match as $field) {
            if ($field === $this->codeKey) {
                continue;
            }
            if (! array_key_exists($field, $item['values'])) {
                return null;
            }
            $scope[$field] = $item['values'][$field];
        }

        return $scope;
    }

    /** The highest code of the file's most common series (e.g. D017 when most codes are D0xx), to continue from. */
    private function usualSeries(array $items): ?string
    {
        $series = [];
        foreach ($items as $item) {
            $code = (string) ($item['values'][$this->codeKey] ?? '');
            if (preg_match('/^(.*?)(\d+)$/', $code, $m)) {
                $series[$m[1]][] = $code;
            }
        }

        if (! $series) {
            return null;
        }

        uasort($series, fn ($a, $b) => count($b) <=> count($a));
        $codes = reset($series);
        usort($codes, fn ($a, $b) => strnatcmp($b, $a));

        return $codes[0];
    }

    /**
     * What to offer for a near miss: the answer of the candidate closest to what was typed.
     *
     * @param  list<array{0: string, 1: string}>  $candidates  [text to compare with, value to offer]
     */
    private function closest(string $text, array $candidates): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;
        $typed = mb_strtolower($text);

        foreach ($candidates as [$candidate, $answer]) {
            $candidate = mb_strtolower($candidate);
            $distance = mb_strlen($typed) >= 3 && str_starts_with($candidate, $typed)
                ? 0 // "Year" for "Yearly (credited upfront)"
                : levenshtein($typed, $candidate);
            if ($distance < $bestDistance) {
                [$best, $bestDistance] = [$answer, $distance];
            }
        }

        return $best !== null && $bestDistance <= max(2, intdiv(mb_strlen($typed), 3)) ? $best : null;
    }

    /** @return list<array{id: int, code: string, label: string, active: bool}> */
    private function targets(array $field): array
    {
        return $this->targets[$field['key']] ??= self::lookupTargets($field);
    }

    /** A value as the sheet shows it. */
    private function display(array $field, mixed $value): string
    {
        $codes = isset($field['lookup']) ? array_column($this->targets($field), 'code', 'id') : [];

        return trim((string) (MasterDataExport::cell($field, $value, $codes) ?? ''));
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        $defaults = [];
        foreach ($this->columns as $field) {
            $defaults[$field['key']] = $field['default'] ?? match ($field['type']) {
                'boolean' => false,
                'multiselect' => [],
                default => null,
            };
        }

        return $defaults;
    }

    /** What a saved record holds, per field: links to other records as a list of ids. */
    private function currentValues(Model $record): array
    {
        $values = [];
        foreach ($this->fields as $key => $field) {
            $values[$key] = $field['type'] === 'multiselect'
                ? $record->{$field['relation']}->modelKeys()
                : $record->getAttribute($key);
        }

        return $values;
    }

    /** @return list<string> The relations the importable multiselect fields are stored in. */
    private function relations(): array
    {
        return array_values(array_map(
            fn (array $f) => $f['relation'],
            array_filter($this->fields, fn (array $f) => $f['type'] === 'multiselect'),
        ));
    }

    /**
     * The multiselect fields whose chosen records differ from what is saved.
     *
     * @return list<string>
     */
    private function changedRelations(array $item): array
    {
        $changed = [];
        foreach ($item['relations'] as $key => $ids) {
            $saved = array_map('intval', $item['record']->{$this->fields[$key]['relation']}->modelKeys());
            sort($saved);
            $new = $ids;
            sort($new);
            if ($saved !== $new) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    // ----- columns --------------------------------------------------------------

    /**
     * Find which sheet column holds which field.
     *
     * @param  list<list<mixed>>  $rows
     * @return array{0: ?array<string, int>, 1: int} [field key => column index, or null; index of the first data row]
     */
    private function locateColumns(array $rows): array
    {
        $accepted = [];
        foreach ($this->columns as $field) {
            $names = array_merge([self::heading($field), $field['label'], $field['key']], $this->definition['import']['aliases'][$field['key']] ?? []);
            foreach ($names as $name) {
                $accepted[$this->normalise($name)] ??= $field['key'];
            }
        }

        // Headings are normally row 1, but allow a title line or two above them.
        foreach (array_slice($rows, 0, self::HEADING_SEARCH_ROWS, true) as $index => $row) {
            $map = [];
            foreach ($row as $column => $heading) {
                $key = is_string($heading) ? ($accepted[$this->normalise($heading)] ?? null) : null;
                if ($key !== null && ! isset($map[$key])) {
                    $map[$key] = $column;
                }
            }

            if (count(array_intersect($this->match, array_keys($map))) === count($this->match)) {
                return [$map, $index + 1];
            }
        }

        return [null, 0];
    }

    private function normalise(string $heading): string
    {
        $normal = Str::of($heading)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();

        return preg_replace('/_optional$/', '', $normal);
    }

    /** Control characters removed; whitespace collapsed (line breaks kept for multi-line text). */
    private function cleanText(mixed $value, bool $multiline = false): string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            $value = (int) $value; // a code typed as 101 comes back as 101.0
        }

        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value) ?? '';

        if ($multiline) {
            $text = preg_replace('/[ \t]+/u', ' ', str_replace("\r\n", "\n", $text)) ?? '';

            return trim($text);
        }

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
