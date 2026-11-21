<?php

namespace App\Exports;

use App\Support\MasterData\MasterDataImporter;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * A master's Excel file, in the layout its import reads back: the data sheet
 * first, then an instructions sheet. Exporting, editing and re-uploading the
 * same file is the intended bulk-edit workflow.
 *
 * The blank template carries the master's two sample rows (highlighted) so
 * people can see how a row is filled in; the import skips them while they are
 * left as they are.
 */
class MasterDataExport implements WithMultipleSheets
{
    use Exportable;

    /**
     * @param  array<string, mixed>  $definition  A MasterDataRegistry entry with an `import` section.
     * @param  iterable<Model>  $records  The rows to export; nothing for the template.
     * @param  bool  $template  Add the sample rows (the downloadable template).
     */
    public function __construct(
        private readonly array $definition,
        private readonly iterable $records = [],
        private readonly bool $template = false,
    ) {}

    public function sheets(): array
    {
        $columns = MasterDataImporter::columns($this->definition);

        // Lookup fields (e.g. a grade's type) are written as the other master's code.
        $codes = [];
        foreach ($columns as $field) {
            if (isset($field['lookup'])) {
                $codes[$field['key']] = array_column(MasterDataImporter::lookupTargets($field), 'code', 'id');
            }
        }

        $rows = [];
        $samples = $this->template ? ($this->definition['import']['examples'] ?? []) : [];
        foreach ($samples as $example) {
            $rows[] = array_map(fn (array $field) => self::cell($field, $example[$field['key']] ?? null), $columns);
        }
        foreach ($this->records as $record) {
            $rows[] = array_map(fn (array $field) => self::cell($field, self::valueOf($field, $record), $codes[$field['key']] ?? []), $columns);
        }

        return [
            new MasterDataSheet($this->definition['import']['sheet'] ?? $this->definition['label'], $columns, $rows, count($samples), $codes),
            new MasterDataGuideSheet($this->definition, $columns, count($samples)),
        ];
    }

    /** A record's value for a field; for a multiselect, the ids of the records it is linked to. */
    private static function valueOf(array $field, Model $record): mixed
    {
        return $field['type'] === 'multiselect'
            ? $record->{$field['relation']}->modelKeys()
            : $record->getAttribute($field['key']);
    }

    /**
     * A value as people type it in the sheet, so the import reads it back unchanged.
     *
     * @param  array<int|string, string>  $codes  For a lookup field: id => code of the master it points to
     *                                            (a multiselect's value is a list of ids, or of codes already).
     */
    public static function cell(array $field, mixed $value, array $codes = []): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return match ($field['type']) {
            'multiselect' => implode(', ', array_map(fn ($item) => $codes[$item] ?? $item, (array) $value)),
            'boolean' => $value ? 'Yes' : 'No',
            'select' => isset($field['lookup'])
                ? ($codes[$value] ?? $value)
                : (collect($field['options'] ?? [])->firstWhere('value', $value)['label'] ?? $value),
            'number' => is_numeric($value) ? $value + 0 : $value, // "10.0" from a decimal column → 10
            default => $value,
        };
    }
}
