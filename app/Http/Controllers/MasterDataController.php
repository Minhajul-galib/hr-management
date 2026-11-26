<?php

namespace App\Http\Controllers;

use App\Exports\MasterDataExport;
use App\Support\Holidays\SpreadsheetRows;
use App\Support\MasterData\Code;
use App\Support\MasterData\MasterDataImporter;
use App\Support\MasterData\MasterDataRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * One controller for every HR master (designations, grades, leave types, ...).
 * What each master looks like is described in MasterDataRegistry; routes are
 * registered per master in routes/web.php so each keeps its own name and
 * permissions (`view`, `create`, `edit`, `delete`, `import`, `export` or full
 * access `manage`, each followed by the master's name).
 *
 * Small masters send every row and the page filters them in the browser.
 * Masters marked `paginate` (departments) are searched and paged here instead,
 * and masters with an `import` section also get Excel import and export.
 *
 * An Excel import is reviewed before it is saved: preview() reads the upload
 * and reports what each row would do, check() re-checks the rows after they
 * are edited on the page, and import() saves the reviewed rows. Nothing is
 * stored until import(). (import() still takes a file directly, too.)
 *
 * Deleting moves a record to the Trash (the masters use SoftDeletes, as Units do). Ticking several
 * rows and deleting them together is bulk(): move to the Trash, restore, or delete for good.
 * `?trash=1` lists the Trash. All of it needs the master's `delete` permission, and a record
 * that is in use (`in_use` in the registry) can be neither trashed nor deleted for good.
 */
class MasterDataController extends Controller
{
    private const PER_PAGE_OPTIONS = [25, 50, 100];

    /** Problem rows of an import sent to the browser; the full count is always sent. */
    private const REPORT_LIST_LIMIT = 100;

    /** Most records one bulk delete / restore may name. */
    private const MAX_BULK = 1000;

    /** How many records that could not be removed are named in the message. */
    private const BLOCKED_LIST_LIMIT = 5;

    public function index(Request $request): Response|RedirectResponse
    {
        $type = $this->type($request);
        $definition = $this->definition($type);
        abort_unless(auth()->user()?->canAny(MasterDataRegistry::opening($definition)), 403);

        $trash = $this->wantsTrash($request, $definition);

        $props = [
            'definition' => $this->publicDefinition($type, $definition),
            'lookups' => $this->lookups($definition),
            // What this person may do here, so the page shows only those buttons.
            'can' => $this->abilities($definition),
            // Flashed by import(); only present on the request right after an upload.
            'importResult' => session('import_result'),
            'importLimits' => isset($definition['import']) ? MasterDataImporter::limits() : null,
            // Showing the Trash instead of the list, and how much is in it (the button says so).
            'trash' => $trash,
            'trashedCount' => $this->trashedCount($definition),
        ];

        // Grades & Pay Scales: each company's salary formulas.
        if (! empty($definition['pay_scales'])) {
            $props['payScales'] = GradePayScaleController::overview();
        }

        // Designations: the categories (Merchandising, Machine Operators, ...) each have their own ladder.
        if (! empty($definition['designation_categories'])) {
            $props['designationCategories'] = DesignationCategoryController::overview();
        }

        if (! isset($definition['paginate'])) {
            $props['records'] = $this->listQuery($definition, $trash)->get()
                ->map(fn (Model $m) => $this->serialize($definition, $m))->values();

            return Inertia::render('MasterData/Index', $props);
        }

        $filters = $this->filters($request, $definition);
        $page = $this->filtered($this->listQuery($definition, $trash), $definition, $filters)
            ->paginate($filters['per_page'])
            ->withQueryString();

        // Past the last page (e.g. its last row was just deleted): show the last page instead.
        if ($page->isEmpty() && $page->currentPage() > 1 && $page->total() > 0) {
            return redirect()->route("{$type}.index", array_merge($this->listParams($request), ['page' => $page->lastPage()]));
        }

        $model = $definition['model'];

        return Inertia::render('MasterData/Index', $props + [
            'records' => collect($page->items())->map(fn (Model $m) => $this->serialize($definition, $m))->values(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'per_page_options' => self::PER_PAGE_OPTIONS,
            ],
            'query' => Arr::except($filters, 'per_page') + ['per_page' => $page->perPage()],
            'stats' => [
                'total' => $model::query()->count(),
                'active' => $model::query()->where('is_active', true)->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $type = $this->type($request);
        $definition = $this->definition($type);
        $this->authorizeFor($definition, 'create');

        $data = $this->validated($request, $definition, null);
        DB::transaction(fn () => $this->persist($definition, new $definition['model'], $data));

        return redirect()->route("{$type}.index", $this->listParams($request))
            ->with('success', "{$definition['singular']} created successfully.");
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $type = $this->type($request);
        $definition = $this->definition($type);
        $this->authorizeFor($definition, 'edit');

        $model = $definition['model']::findOrFail($id);
        $data = $this->validated($request, $definition, $model->getKey());
        DB::transaction(fn () => $this->persist($definition, $model, $data));

        return redirect()->route("{$type}.index", $this->listParams($request))
            ->with('success', "{$definition['singular']} updated successfully.");
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $type = $this->type($request);
        $definition = $this->definition($type);
        $this->authorizeFor($definition, 'delete');

        $model = $definition['model']::findOrFail($id);

        $reason = isset($definition['in_use']) ? $definition['in_use']($model) : null;
        if ($reason) {
            return redirect()->route("{$type}.index", $this->listParams($request))->with(
                'error',
                "This {$definition['singular']} cannot be deleted because {$reason}. Mark it inactive instead.",
            );
        }

        $model->delete(); // to the Trash

        return redirect()->route("{$type}.index", $this->listParams($request))
            ->with('success', "{$definition['singular']} moved to Trash.");
    }

    /**
     * Ticked rows, all at once: `action` is `trash` (move to the Trash), `restore` (back out of it)
     * or `force` (delete for good, from the list or the Trash). Records that are in use are left alone and named.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $type = $this->type($request);
        $definition = $this->definition($type);
        abort_unless($this->supportsTrash($definition), 404);
        $this->authorizeFor($definition, 'delete');

        $action = (string) $request->input('action');
        if (! in_array($action, ['trash', 'restore', 'force'], true)) {
            throw ValidationException::withMessages(['action' => 'Choose what to do with the selected rows.']);
        }

        $ids = $request->input('ids');
        if (! is_array($ids) || ! array_is_list($ids) || $ids === [] || count($ids) > self::MAX_BULK) {
            throw ValidationException::withMessages(['ids' => 'Select between 1 and '.number_format(self::MAX_BULK).' rows.']);
        }
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_scalar'))));

        $model = $definition['model'];
        // Moving to the Trash takes live records, restoring takes those in the Trash, and deleting for good
        // takes either (from the list, or from the Trash).
        $records = match ($action) {
            'trash' => $model::query(),
            'restore' => $model::onlyTrashed(),
            'force' => $model::withTrashed(),
        };
        $records = $records->whereKey($ids)->get();

        $done = 0;
        $blocked = [];
        foreach ($records as $record) {
            $reason = $action !== 'restore' && isset($definition['in_use']) ? $definition['in_use']($record) : null;

            try {
                if ($reason === null) {
                    DB::transaction(fn () => match ($action) {
                        'trash' => $record->delete(),
                        'restore' => $record->restore(),
                        'force' => $record->forceDelete(),
                    });
                    $done++;
                    continue;
                }
            } catch (QueryException $e) {
                // Something else still points at it (e.g. a row of another table).
                Log::warning("{$definition['label']}: could not delete #{$record->getKey()}.", ['error' => $e->getMessage()]);
                $reason = 'other records still depend on it';
            }

            $blocked[] = Str::limit((string) $record->getAttribute($definition['title_key']), 40)." ({$reason})";
        }

        $back = redirect()->route("{$type}.index", $this->listParams($request));
        if ($done === 0 && $blocked === []) {
            return $back->with('error', 'Nothing was found to '.['trash' => 'move to the Trash', 'restore' => 'restore', 'force' => 'delete'][$action].'. It may already have been changed.');
        }

        if ($done > 0) {
            $noun = mb_strtolower($done === 1 ? $definition['singular'] : $definition['label']);
            $back->with('success', "{$done} {$noun} ".['trash' => 'moved to the Trash', 'restore' => 'restored', 'force' => 'deleted permanently'][$action].'.');
        }
        if ($blocked) {
            $shown = implode('; ', array_slice($blocked, 0, self::BLOCKED_LIST_LIMIT));
            $more = count($blocked) > self::BLOCKED_LIST_LIMIT ? '; and '.(count($blocked) - self::BLOCKED_LIST_LIMIT).' more' : '';
            $back->with('error', count($blocked).' could not be '.($action === 'force' ? 'deleted' : 'moved to the Trash').": {$shown}{$more}. Mark them inactive instead.");
        }

        return $back;
    }

    // ----- Excel import / export ------------------------------------------------

    /** Read an uploaded sheet and report what importing it would do. Nothing is saved. */
    public function preview(Request $request): JsonResponse
    {
        $type = $this->type($request);
        $definition = $this->importable($type);
        $this->authorizeFor($definition, 'import');

        $this->validateUpload($request);
        $file = $request->file('file');
        $importer = new MasterDataImporter($definition);

        $read = $this->readUpload($definition, $importer, $file);
        if ($read['fatal'] !== null) {
            return response()->json(['message' => $read['fatal']], 422);
        }
        if ($read['records'] === []) {
            return response()->json(['message' => 'The file has no '.mb_strtolower($definition['label']).' to import.'], 422);
        }

        return response()->json(['file' => $file->getClientOriginalName()] + $importer->check($read['records']));
    }

    /** Check rows edited on the review screen again. Nothing is saved. */
    public function check(Request $request): JsonResponse
    {
        $type = $this->type($request);
        $definition = $this->importable($type);
        $this->authorizeFor($definition, 'import');

        return response()->json((new MasterDataImporter($definition))->check($this->submittedRows($request)));
    }

    /**
     * Save an import: the rows confirmed on the review screen (`rows`), or an
     * uploaded file (`file`) imported as it is. Rows with problems are skipped
     * and listed; the rest are saved.
     */
    public function import(Request $request): RedirectResponse
    {
        $type = $this->type($request);
        $definition = $this->importable($type);
        $this->authorizeFor($definition, 'import');

        $back = redirect()->route("{$type}.index", $this->listParams($request));
        $importer = new MasterDataImporter($definition);

        if ($request->has('rows')) {
            $fileName = mb_substr(trim((string) $request->input('file_name')), 0, 255) ?: 'Reviewed rows';
            $result = $importer->commit($this->submittedRows($request));
        } else {
            $this->validateUpload($request);
            $file = $request->file('file');
            $fileName = $file->getClientOriginalName();

            $read = $this->readUpload($definition, $importer, $file);
            $result = $read['fatal'] !== null
                ? ['total' => $read['total'], 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'samples' => 0, 'errors' => [], 'fatal' => $read['fatal']]
                : $importer->commit($read['records']);
        }

        $saved = $result['created'] + $result['updated'];
        $errors = count($result['errors']);
        $plural = mb_strtolower($definition['label']);

        $request->session()->flash('import_result', [
            'file' => $fileName,
            'total' => $result['total'],
            'fatal' => $result['fatal'],
            'created_count' => $result['created'],
            'updated_count' => $result['updated'],
            'unchanged_count' => $result['unchanged'],
            'sample_count' => $result['samples'],
            'error_count' => $errors,
            'errors' => array_slice($result['errors'], 0, self::REPORT_LIST_LIMIT),
        ]);

        if ($result['fatal'] !== null) {
            return $back->with('error', $result['fatal']);
        }
        if ($result['total'] === $result['samples']) {
            return $back->with('error', $result['total'] === 0
                ? "The file has no {$plural} to import."
                : "The file only has the template's sample rows. Type your own {$plural} over them and upload it again.");
        }
        if ($saved === 0 && $errors > 0) {
            return $back->with('error', "Nothing was imported — {$errors} ".($errors === 1 ? 'row needs' : 'rows need').' attention.');
        }

        $message = $saved === 0
            ? 'Everything in the file is already up to date.'
            : "Import done: {$result['created']} added, {$result['updated']} updated.";
        if ($errors > 0) {
            $message .= " {$errors} ".($errors === 1 ? 'row was' : 'rows were').' skipped because of problems.';
        }

        return $back->with('success', $message);
    }

    public function template(Request $request): BinaryFileResponse
    {
        $type = $this->type($request);
        $definition = $this->importable($type);
        $this->authorizeFor($definition, 'import');

        return (new MasterDataExport($definition, template: true))->download("{$type}-import-template.xlsx");
    }

    /** The list as an Excel file in the import layout, with the page's search and filters applied. */
    public function export(Request $request): BinaryFileResponse
    {
        $type = $this->type($request);
        $definition = $this->importable($type);
        $this->authorizeFor($definition, 'export');

        $records = $this->filtered($this->listQuery($definition), $definition, $this->filters($request, $definition))->get();

        return (new MasterDataExport($definition, $records))->download("{$type}-".now()->format('Y-m-d').'.xlsx');
    }

    private function validateUpload(Request $request): void
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx,xls,csv', 'max:'.MasterDataImporter::MAX_UPLOAD_KB],
        ], [
            'file.required' => 'Choose an Excel file to upload.',
            'file.extensions' => 'Upload an Excel file (.xlsx or .xls) or a .csv file.',
            'file.max' => 'The file is too large — '.(MasterDataImporter::MAX_UPLOAD_KB / 1024).' MB at most.',
        ]);
    }

    /** @return array{records: list<array<string, mixed>>, total: int, fatal: ?string} */
    private function readUpload(array $definition, MasterDataImporter $importer, UploadedFile $file): array
    {
        try {
            $rows = SpreadsheetRows::read($file->getRealPath(), $file->getClientOriginalExtension(), $definition['import']['sheet'] ?? $definition['label']);
        } catch (\Throwable $e) {
            Log::warning("{$definition['label']} import: could not read the uploaded sheet.", ['error' => $e->getMessage()]);

            return ['records' => [], 'total' => 0, 'fatal' => 'That file could not be read. Upload an .xlsx, .xls or .csv file made from the template.'];
        }

        return $importer->read($rows);
    }

    /**
     * Rows sent back from the review screen: [{row, cells: {field: text}, sample}].
     * Checked by hand rather than with wildcard rules, which are slow on 2,000 rows.
     *
     * @return list<array{row: int, cells: array<string, string>, sample: bool}>
     */
    private function submittedRows(Request $request): array
    {
        $rows = $request->input('rows');

        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > MasterDataImporter::MAX_ROWS) {
            throw ValidationException::withMessages([
                'rows' => 'Send between 1 and '.number_format(MasterDataImporter::MAX_ROWS).' rows.',
            ]);
        }

        $clean = [];
        foreach ($rows as $row) {
            $cells = is_array($row['cells'] ?? null) ? $row['cells'] : [];
            $clean[] = [
                'row' => max(0, min(1_000_000, (int) ($row['row'] ?? 0))),
                'cells' => array_map(fn ($v) => is_scalar($v) ? mb_substr((string) $v, 0, 5000) : '', $cells),
                'sample' => filter_var($row['sample'] ?? false, FILTER_VALIDATE_BOOL),
            ];
        }

        return $clean;
    }

    /**
     * Which master this request is for. It comes from the route's `type` default,
     * read by name: Laravel hands route parameters to controller methods by
     * position (path parameters first, defaults last), so a `$type` argument
     * would be fragile.
     */
    private function type(Request $request): string
    {
        return (string) $request->route('type');
    }

    /** @return array<string, mixed> */
    private function definition(string $type): array
    {
        return MasterDataRegistry::find($type) ?? abort(404);
    }

    /** @return array<string, mixed> A master that supports Excel import/export. */
    private function importable(string $type): array
    {
        $definition = $this->definition($type);
        abort_unless(isset($definition['import']), 404);

        return $definition;
    }

    private function listQuery(array $definition, bool $trash = false): Builder
    {
        $query = $definition['model']::query()->with([...$this->relations($definition), ...($definition['with'] ?? [])]);
        if ($trash) {
            $query->onlyTrashed();
        }
        foreach ($definition['order'] as [$column, $direction]) {
            $query->orderBy($column, $direction);
        }

        return $query;
    }

    /**
     * Search and status filter from the query string, cleaned up.
     *
     * @return array{search: string, status: string, per_page: int}
     */
    private function filters(Request $request, array $definition): array
    {
        $perPage = (int) $request->query('per_page');
        $status = (string) $request->query('status');

        return [
            'search' => mb_substr(trim((string) $request->query('search')), 0, 100),
            'status' => in_array($status, ['active', 'inactive'], true) ? $status : '',
            'per_page' => in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : ($definition['paginate']['per_page'] ?? self::PER_PAGE_OPTIONS[0]),
        ];
    }

    /** Every word must appear in one of the master's search columns, ignoring case. */
    private function filtered(Builder $query, array $definition, array $filters): Builder
    {
        if ($filters['status'] !== '') {
            $query->where('is_active', $filters['status'] === 'active');
        }

        $columns = $definition['paginate']['search'] ?? [];
        $words = preg_split('/\s+/u', $filters['search'], -1, PREG_SPLIT_NO_EMPTY);

        foreach ($columns ? $words : [] as $word) {
            $like = '%'.addcslashes(mb_strtolower($word), '\\%_').'%';
            $query->where(function (Builder $q) use ($columns, $like) {
                foreach ($columns as $column) {
                    $q->orWhereRaw('LOWER('.$q->getQuery()->getGrammar()->wrap($column).') LIKE ?', [$like]);
                }
            });
        }

        return $query;
    }

    /**
     * The list position (search, status, page size, page) to come back to after a
     * change. The page sends it on the request URL; anything else is dropped.
     *
     * @return array<string, string|int>
     */
    private function listParams(Request $request): array
    {
        return array_filter(
            Arr::only($request->query(), ['search', 'status', 'per_page', 'page', 'trash']),
            fn ($value) => is_scalar($value) && $value !== '',
        );
    }

    /** Whether records of this master go to a Trash first (its model uses SoftDeletes). */
    private function supportsTrash(array $definition): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($definition['model']), true);
    }

    /** Is the Trash being asked for? Seeing it takes the `delete` permission, as emptying it does. */
    private function wantsTrash(Request $request, array $definition): bool
    {
        if (! $request->boolean('trash') || ! $this->supportsTrash($definition)) {
            return false;
        }
        $this->authorizeFor($definition, 'delete');

        return true;
    }

    /** How many records are in the Trash; only counted for those who may open it. */
    private function trashedCount(array $definition): int
    {
        if (! $this->supportsTrash($definition) || ! auth()->user()?->canAny(MasterDataRegistry::allowing($definition['permission'], 'delete'))) {
            return 0;
        }

        return $definition['model']::onlyTrashed()->count();
    }

    /**
     * Abort unless the user holds this action's permission or full access (`manage`).
     * A master without that action's own permission may name the one that covers it
     * (`permissions` => ['import' => 'create'] for Units, whose Excel import is part of adding units).
     */
    private function authorizeFor(array $definition, string $action): void
    {
        $action = $definition['permissions'][$action] ?? $action;

        abort_unless(auth()->user()?->canAny(MasterDataRegistry::allowing($definition['permission'], $action)), 403);
    }

    /**
     * What the user may do on this master, by action. The page reads it to decide
     * which buttons to show; the routes enforce the same permissions.
     *
     * @return array<string, bool>
     */
    private function abilities(array $definition): array
    {
        $user = auth()->user();
        $excel = isset($definition['import']);

        return [
            'create' => $user->canAny(MasterDataRegistry::allowing($definition['permission'], 'create')),
            'edit' => $user->canAny(MasterDataRegistry::allowing($definition['permission'], 'edit')),
            'delete' => $user->canAny(MasterDataRegistry::allowing($definition['permission'], 'delete')),
            'import' => $excel && $user->canAny(MasterDataRegistry::allowing($definition['permission'], 'import')),
            'export' => $excel && $user->canAny(MasterDataRegistry::allowing($definition['permission'], 'export')),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, array $definition, ?int $ignoreId): array
    {
        // Codes are stored in small letters and short names in capitals; normalise first
        // so "ABC" can't slip past a unique "abc".
        foreach ($definition['fields'] as $field) {
            $value = $request->input($field['key']);
            if (! empty($field['uppercase']) && is_string($value)) {
                $request->merge([$field['key'] => strtoupper(trim($value))]);
            } elseif (! empty($field['lowercase'])) {
                $request->merge([$field['key'] => Code::normalise($value)]);
            }
        }

        return $request->validate($definition['rules']($ignoreId, $request->all()), MasterDataRegistry::MESSAGES);
    }

    /** @param array<string, mixed> $data */
    private function persist(array $definition, Model $model, array $data): Model
    {
        $relationFields = array_filter($definition['fields'], fn ($f) => $f['type'] === 'multiselect');

        $attributes = Arr::except($data, array_column($relationFields, 'key'));
        if (isset($definition['prepare'])) {
            $attributes = $definition['prepare']($attributes);
        }

        $model->fill($attributes)->save();

        foreach ($relationFields as $field) {
            $model->{$field['relation']}()->sync($data[$field['key']] ?? []);
        }

        return $model;
    }

    /** @return list<string> */
    private function relations(array $definition): array
    {
        return array_values(array_map(
            fn ($f) => $f['relation'],
            array_filter($definition['fields'], fn ($f) => $f['type'] === 'multiselect'),
        ));
    }

    /** @return array<string, mixed> */
    private function serialize(array $definition, Model $model): array
    {
        $row = ['id' => $model->getKey()];

        foreach ($definition['fields'] as $field) {
            $row[$field['key']] = $field['type'] === 'multiselect'
                ? $model->{$field['relation']}->pluck('id')->all()
                : $model->getAttribute($field['key']);
        }

        // Values worked out from the record, for masters that define `computed`.
        if (isset($definition['computed'])) {
            $row += ($definition['computed'])($model);
        }

        return $row;
    }

    /** Option lists for fields that pick from another master, keyed by field key. */
    private function lookups(array $definition): array
    {
        $lookups = [];

        foreach ($definition['fields'] as $field) {
            // multiselect and lookup-backed selects both draw their options from another master
            if (! isset($field['lookup'])) {
                continue;
            }

            $spec = $field['lookup'];
            $query = $spec['model']::query()->with($spec['with'] ?? []);
            foreach ((array) ($spec['order'] ?? 'name') as $column) {
                $query->orderBy($column);
            }

            $lookups[$field['key']] = $query
                ->get()
                ->map(fn (Model $m) => [
                    'value' => $m->getKey(),
                    // A master without a status (e.g. salary formulas) is always active.
                    'label' => $spec['label']($m).(($m->is_active ?? true) ? '' : ' (inactive)'),
                    'active' => (bool) ($m->is_active ?? true),
                ] + (isset($spec['extra']) ? $spec['extra']($m) : []))
                ->values();
        }

        return $lookups;
    }

    /** What the page needs to render the master; server-side closures stay behind. */
    private function publicDefinition(string $type, array $definition): array
    {
        return [
            'key' => $type,
            'label' => $definition['label'],
            'singular' => $definition['singular'],
            'description' => $definition['description'],
            'title_key' => $definition['title_key'],
            'columns' => $definition['columns'],
            'filters' => $definition['filters'] ?? [],
            'paginate' => isset($definition['paginate']),
            'trash' => $this->supportsTrash($definition),
            'import' => isset($definition['import']) ? MasterDataImporter::describe($definition) : null,
            'fields' => array_map(fn ($f) => Arr::except($f, ['lookup', 'relation']), $definition['fields']),
        ];
    }
}
