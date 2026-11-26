<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Salary is now worked out from formulas the user types, one pay scale per company:
 *
 *  - Group (both Group types): Basic = [Grade] * 25 + 950, House Rent = [Basic] / 2,
 *    Conveyance = 2000, Gross = [Basic] + [House Rent] + [Conveyance]
 *  - VBL (both VBL types):     Gross = [Grade] * 25 + 950, Basic = [Gross] * 60%,
 *    House Rent = [Gross] * 30%, Medical = [Gross] * 10%
 *
 * Male and Female / 65+ grades of a company share its formulas; only their tax will differ
 * (tax comes later). So the four per-type pay scales become two per-company ones, every line
 * becomes a title and a formula, and the salary modules connected to a type become lines too
 * (their rate turned into a formula). The fixed kinds of calculation, the "base" flag and the
 * pay-scale-to-salary-module link table are dropped: a line that is a salary module is linked
 * to it by `salary_type_id` instead (matched by name).
 *
 * The Group and VBL formulas above are added where a company has nothing else yet (Group had
 * only the Basic it was seeded with); anything already set up is kept as it is.
 */
return new class extends Migration
{
    /** company => its types, Male first */
    private const COMPANIES = [
        'group' => ['group_male', 'group_female_65'],
        'vbl' => ['vbl_male', 'vbl_female_65'],
    ];

    private const SEED = [
        'group' => [
            ['Basic', '[Grade] * 25 + 950'],
            ['House Rent', '[Basic] / 2'],
            ['Conveyance', '2000'],
            ['Gross', '[Basic] + [House Rent] + [Conveyance]'],
        ],
        'vbl' => [
            ['Gross', '[Grade] * 25 + 950'],
            ['Basic', '[Gross] * 60%'],
            ['House Rent', '[Gross] * 30%'],
            ['Medical', '[Gross] * 10%'],
        ],
    ];

    public function up(): void
    {
        Schema::table('pay_scales', function (Blueprint $table) {
            $table->string('company', 20)->nullable()->after('id');
        });
        Schema::table('pay_scale_lines', function (Blueprint $table) {
            $table->string('formula', 500)->nullable()->after('title');
            $table->foreignId('salary_type_id')->nullable()->after('formula')->constrained()->restrictOnDelete();
        });

        $this->turnLinesIntoFormulas();
        $this->turnConnectedModulesIntoLines();
        $this->mergeTypesIntoCompanies();
        $this->seedCompanyFormulas();
        $this->linkSalaryModulesByName();

        Schema::table('pay_scale_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_line_id');
            $table->dropColumn(['kind', 'multiplier', 'amount', 'percent', 'is_base']);
        });
        Schema::table('pay_scale_lines', function (Blueprint $table) {
            $table->string('formula', 500)->nullable(false)->change();
        });
        Schema::table('pay_scales', function (Blueprint $table) {
            $table->dropColumn('type');
        });
        Schema::table('pay_scales', function (Blueprint $table) {
            $table->string('company', 20)->nullable(false)->change();
            $table->unique('company');
        });

        Schema::dropIfExists('pay_scale_salary_type');
    }

    /**
     * Back to one pay scale per type with fixed kinds of calculation. Formulas cannot be turned
     * back into those, so the lines are not kept; each company's scale goes to its Male type.
     */
    public function down(): void
    {
        Schema::create('pay_scale_salary_type', function (Blueprint $table) {
            $table->foreignId('pay_scale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_type_id')->constrained()->restrictOnDelete();
            $table->primary(['pay_scale_id', 'salary_type_id']);
            $table->index('salary_type_id');
        });

        DB::table('pay_scale_lines')->delete();

        Schema::table('pay_scale_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('salary_type_id');
            $table->dropColumn('formula');
            $table->string('kind', 20)->default('fixed');
            $table->decimal('multiplier', 12, 2)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->decimal('percent', 8, 2)->nullable();
            $table->foreignId('source_line_id')->nullable()->constrained('pay_scale_lines')->nullOnDelete();
            $table->boolean('is_base')->default(false);
        });

        Schema::table('pay_scales', function (Blueprint $table) {
            $table->string('type', 30)->nullable();
        });
        $now = now();
        foreach (self::COMPANIES as $company => [$male, $female]) {
            DB::table('pay_scales')->where('company', $company)->update(['type' => $male]);
            DB::table('pay_scales')->insert(['company' => "{$company}_x", 'type' => $female, 'created_at' => $now, 'updated_at' => $now]);
        }
        Schema::table('pay_scales', function (Blueprint $table) {
            $table->dropUnique(['company']);
            $table->dropColumn('company');
        });
        Schema::table('pay_scales', function (Blueprint $table) {
            $table->unique('type');
        });
    }

    /** grade x 25 + 950, 10% of Basic and 2000 become "[Grade] * 25 + 950", "[Basic] * 10%" and "2000". */
    private function turnLinesIntoFormulas(): void
    {
        $titles = DB::table('pay_scale_lines')->pluck('title', 'id');

        foreach (DB::table('pay_scale_lines')->get() as $line) {
            $formula = match ($line->kind) {
                'grade_formula' => '[Grade] * '.$this->plain($line->multiplier).((float) $line->amount != 0 ? ' + '.$this->plain($line->amount) : ''),
                'percent' => isset($titles[$line->source_line_id]) ? "[{$titles[$line->source_line_id]}] * {$this->plain($line->percent)}%" : '0',
                default => $this->plain($line->amount),
            };

            DB::table('pay_scale_lines')->where('id', $line->id)->update(['formula' => $formula]);
        }
    }

    /** A salary module connected to a type becomes a line of it: "[Basic] * 50%" or its fixed amount. */
    private function turnConnectedModulesIntoLines(): void
    {
        $now = now();

        foreach (DB::table('pay_scales')->get() as $scale) {
            $lines = DB::table('pay_scale_lines')->where('pay_scale_id', $scale->id);
            $base = (clone $lines)->where('is_base', true)->value('title');
            $taken = (clone $lines)->pluck('title')->map(fn ($t) => mb_strtolower($t))->all();
            $position = (int) (clone $lines)->max('position');

            $modules = DB::table('salary_types')
                ->join('pay_scale_salary_type', 'pay_scale_salary_type.salary_type_id', '=', 'salary_types.id')
                ->where('pay_scale_salary_type.pay_scale_id', $scale->id)
                ->orderBy('salary_types.code')
                ->get(['salary_types.id', 'salary_types.name', 'salary_types.calculation', 'salary_types.value']);

            foreach ($modules as $module) {
                if (in_array(mb_strtolower($module->name), $taken, true)) {
                    continue;
                }
                if ($module->calculation === 'percent_of_basic' && $base === null) {
                    continue; // a share of a basic this type does not have
                }

                DB::table('pay_scale_lines')->insert([
                    'pay_scale_id' => $scale->id,
                    'position' => ++$position,
                    'title' => $module->name,
                    'formula' => $module->calculation === 'percent_of_basic' ? "[{$base}] * {$this->plain($module->value)}%" : $this->plain($module->value),
                    'salary_type_id' => $module->id,
                    'kind' => 'fixed',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $taken[] = mb_strtolower($module->name);
            }
        }
    }

    /** Each company keeps one scale: its Male type's, or the Female / 65+ one when only that has lines. */
    private function mergeTypesIntoCompanies(): void
    {
        $now = now();

        foreach (self::COMPANIES as $company => $types) {
            $scales = DB::table('pay_scales')->whereIn('type', $types)->get()->sortBy(fn ($s) => array_search($s->type, $types, true))->values();
            $keep = $scales->first(fn ($s) => DB::table('pay_scale_lines')->where('pay_scale_id', $s->id)->exists()) ?? $scales->first();

            if ($keep === null) {
                DB::table('pay_scales')->insert(['company' => $company, 'type' => "{$company}_merged", 'created_at' => $now, 'updated_at' => $now]);

                continue;
            }

            DB::table('pay_scales')->where('id', $keep->id)->update(['company' => $company]);
            DB::table('pay_scales')->whereIn('type', $types)->where('id', '!=', $keep->id)->delete();
        }
    }

    private function seedCompanyFormulas(): void
    {
        $now = now();

        foreach (self::SEED as $company => $lines) {
            $scaleId = DB::table('pay_scales')->where('company', $company)->value('id');
            $titles = DB::table('pay_scale_lines')->where('pay_scale_id', $scaleId)->pluck('formula', 'title')->all();

            // Group was seeded with just Basic = [Grade] * 25 + 950; anything else means the user set it up.
            $untouched = $titles === [] || $titles === ['Basic' => '[Grade] * 25 + 950'];
            if (! $untouched) {
                continue;
            }

            DB::table('pay_scale_lines')->where('pay_scale_id', $scaleId)->delete();
            foreach ($lines as $position => [$title, $formula]) {
                DB::table('pay_scale_lines')->insert([
                    'pay_scale_id' => $scaleId, 'position' => $position, 'title' => $title, 'formula' => $formula,
                    'kind' => 'fixed', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    /** A line titled like a salary module (House Rent, Conveyance, ...) is that module. */
    private function linkSalaryModulesByName(): void
    {
        $modules = DB::table('salary_types')->get(['id', 'name'])->keyBy(fn ($m) => mb_strtolower(trim($m->name)));

        foreach (DB::table('pay_scale_lines')->whereNull('salary_type_id')->get(['id', 'title']) as $line) {
            if ($module = $modules->get(mb_strtolower(trim($line->title)))) {
                DB::table('pay_scale_lines')->where('id', $line->id)->update(['salary_type_id' => $module->id]);
            }
        }
    }

    private function plain(string|float|int|null $number): string
    {
        return rtrim(rtrim(number_format((float) $number, 2, '.', ''), '0'), '.');
    }
};
