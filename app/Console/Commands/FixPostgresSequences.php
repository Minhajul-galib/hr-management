<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixPostgresSequences extends Command
{
    protected $signature   = 'db:fix-sequences {--table= : Fix only this table}';
    protected $description = 'Reset PostgreSQL sequences to max(id) so auto-increment inserts do not fail after bulk data loads.';

    public function handle(): int
    {
        $only = $this->option('table');

        // Find all tables that have an integer/bigint 'id' column with an attached sequence.
        $tables = DB::select("
            SELECT
                t.relname AS table_name,
                pg_get_serial_sequence(quote_ident(t.relname), 'id') AS seq_name
            FROM pg_class t
            JOIN pg_namespace n ON n.oid = t.relnamespace
            WHERE t.relkind = 'r'
              AND n.nspname = 'public'
              AND EXISTS (
                  SELECT 1 FROM pg_attribute a
                  WHERE a.attrelid = t.oid
                    AND a.attname = 'id'
                    AND a.attnum > 0
                    AND NOT a.attisdropped
              )
            ORDER BY t.relname
        ");

        $fixed  = 0;
        $errors = 0;

        foreach ($tables as $row) {
            $table   = $row->table_name;
            $seqName = $row->seq_name;

            if ($only && $table !== $only) {
                continue;
            }

            if (!$seqName) {
                $this->line("  <fg=gray>skip</>  {$table} — no sequence attached");
                continue;
            }

            try {
                $result = DB::selectOne("
                    SELECT setval(
                        '{$seqName}',
                        COALESCE((SELECT MAX(id) FROM \"{$table}\"), 0) + 1,
                        false          -- next call to nextval() will return this value
                    ) AS new_val
                ");

                $this->line("  <info>ok</>    {$table} → next id = {$result->new_val}");
                $fixed++;
            } catch (\Throwable $e) {
                $this->line("  <error>fail</error>  {$table} — {$e->getMessage()}");
                $errors++;
            }
        }

        $this->newLine();
        $this->info("Done. Fixed: {$fixed} table(s)" . ($errors ? ", Errors: {$errors}" : '.'));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
