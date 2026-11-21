<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Master-data codes are now numbers and small letters only, with at least one
 * number (see App\Support\MasterData\Code). Codes saved under the old rule
 * (capitals, dashes, dots, underscores) are converted so they still match what
 * people type and import: D001 → d001, G-01 → g01, NATIONAL_ID → nationalid1.
 *
 * Two codes that become the same keep the first and number the other (g01, g012).
 * Nothing refers to a code yet, so this is the cheapest moment to do it. The
 * old spelling is not kept, so down() cannot restore it.
 */
return new class extends Migration
{
    /** table => column holding its code */
    private const CODES = [
        'departments' => 'code',
        'designations' => 'code',
        'salary_types' => 'code',
        'grades' => 'code',
        'leave_types' => 'code',
        'offence_types' => 'code',
        'document_types' => 'code',
        'units' => 'unit_code',
    ];

    private const MAX_LENGTH = 50;

    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::CODES as $table => $column) {
                $this->convert($table, $column);
            }
        });
    }

    public function down(): void
    {
        // The capitals and symbols that were dropped cannot be brought back.
    }

    private function convert(string $table, string $column): void
    {
        $taken = [];
        $changes = [];

        foreach (DB::table($table)->orderBy('id')->get(['id', $column]) as $row) {
            $old = (string) $row->{$column};
            $new = $this->small($old);

            for ($n = 2, $candidate = $new; isset($taken[$candidate]); $n++) {
                $candidate = substr($new, 0, self::MAX_LENGTH - strlen((string) $n)).$n;
            }
            $taken[$candidate] = true;

            if ($candidate !== $old) {
                $changes[$row->id] = $candidate;
            }
        }

        // In two steps, so codes that swap or collide on the way never meet in the unique index.
        foreach ($changes as $id => $code) {
            DB::table($table)->where('id', $id)->update([$column => '~'.$id]);
        }
        foreach ($changes as $id => $code) {
            DB::table($table)->where('id', $id)->update([$column => $code]);
        }
    }

    private function small(string $code): string
    {
        $code = substr(preg_replace('/[^a-z0-9]+/', '', strtolower($code)) ?? '', 0, self::MAX_LENGTH);
        if ($code === '') {
            $code = 'code';
        }

        return preg_match('/\d/', $code) ? $code : substr($code, 0, self::MAX_LENGTH - 1).'1';
    }
};
