<?php

namespace Tests\Unit;

use App\Support\Payroll\Formula;
use App\Support\Payroll\PayScaleCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SalaryFormulaTest extends TestCase
{
    private const AMOUNTS = ['grade' => 400, 'gross' => 10950, 'basic' => 10950, 'house rent' => 5475, 'conveyance' => 2000];

    private function work(string $formula): ?float
    {
        return Formula::parse($formula)->evaluate(fn (string $name) => self::AMOUNTS[$name] ?? null);
    }

    /** @return array<string, array{0: string, 1: float|null}> */
    public static function formulas(): array
    {
        return [
            'grade formula' => ['[Grade] * 25 + 950', 10950.0],
            'share of gross' => ['[Gross] * 60%', 6570.0],
            'half of basic' => ['[Basic] / 2', 5475.0],
            'fixed' => ['2000', 2000.0],
            'decimal' => ['0.5 * 3', 1.5],
            'sum, any spacing or case' => ['[Basic] + [house   RENT] + [ Conveyance ]', 18425.0],
            'precedence' => ['2 + 3 * 4', 14.0],
            'brackets' => ['(2 + 3) * 4', 20.0],
            'minus' => ['-(2 + 3) * 2', -10.0],
            'percent of a bracket' => ['(50 + 10)% * 100', 60.0],
            'typed signs' => ['2 × 3 ÷ 4', 1.5],
            'min' => ['min(1, 2, 3)', 1.0],
            'max' => ['max([Basic] * 10%, 500)', 1095.0],
            'round' => ['round(1234.567)', 1235.0],
            'round to decimals' => ['round(1234.567, 1)', 1234.6],
            'nested' => ['round(max([Gross] / 3, 1000))', 3650.0],
            'divide by zero' => ['10 / ([Basic] - [Basic])', null],
            'unknown name' => ['[Nope] + 1', null],
        ];
    }

    #[DataProvider('formulas')]
    public function test_formulas_work_out(string $formula, ?float $expected): void
    {
        $this->assertSame($expected, $this->work($formula));
    }

    public function test_it_lists_the_names_it_uses_once_each(): void
    {
        $formula = Formula::parse('[Basic] + [basic] + [House  Rent] * [Grade]');

        $this->assertSame(['basic', 'house rent', 'grade'], $formula->references());
        $this->assertSame(['basic' => 'Basic', 'house rent' => 'House  Rent', 'grade' => 'Grade'], $formula->referenceNames());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function mistakes(): array
    {
        return [
            'empty' => ['', 'Enter a formula'],
            'unclosed name' => ['[Basic', 'A [ has no closing ]'],
            'empty name' => ['[] * 2', '[ ] is empty'],
            'name without brackets' => ['basic * 2', 'Put names in square brackets, e.g. [basic]'],
            'thousands comma' => ['2,000', 'Write numbers without commas'],
            'unclosed bracket' => ['(1 + 2', 'A ( has no closing )'],
            'ends too soon' => ['1 +', 'ends too soon'],
            'missing operator' => ['1 2', 'missing + - * /'],
            'nothing in round' => ['round()', 'Something is missing before ")"'],
            'round with three' => ['round(1, 2, 3)', 'round takes a value'],
            'stray close' => ['[Basic]]', 'A ] has no opening ['],
            'odd character' => ['5 $', '"$" cannot be used'],
            'function without brackets' => ['min', 'min needs brackets'],
            'starts with an operator' => ['* 3', 'Something is missing before "*"'],
            'too long' => [str_repeat('1+', 300).'1', 'too long'],
        ];
    }

    #[DataProvider('mistakes')]
    public function test_a_mistake_is_explained(string $formula, string $message): void
    {
        $this->assertStringContainsString($message, (string) Formula::problem($formula));
    }

    public function test_a_component_list_is_checked_for_titles_names_and_loops(): void
    {
        $this->assertSame([], PayScaleCheck::errors([
            ['title' => 'Gross', 'formula' => '[Grade] * 25 + 950'],
            ['title' => 'Basic', 'formula' => '[Gross] * 60%'],      // uses one above it...
            ['title' => 'Medical', 'formula' => '[Total] * 1%'],     // ...or one below it
            ['title' => 'Total', 'formula' => '[Basic] + 1'],
        ]));

        $errors = PayScaleCheck::errors([
            ['title' => 'A', 'formula' => '[B] + 1'],
            ['title' => 'B', 'formula' => '[C] + 1'],
            ['title' => 'C', 'formula' => '[A] + 1'],
            ['title' => 'D', 'formula' => '[D]'],
            ['title' => 'E', 'formula' => '[A] + 1'],   // uses a loop but is not in it
            ['title' => 'a', 'formula' => '1'],
        ]);

        $this->assertSame('[A], [B] and [C] use each other, so none of them can be worked out.', $errors['lines.0.formula']);
        $this->assertSame($errors['lines.0.formula'], $errors['lines.2.formula']);
        $this->assertSame('[D] uses itself, so it can never be worked out.', $errors['lines.3.formula']);
        $this->assertArrayNotHasKey('lines.4.formula', $errors);
        $this->assertSame('Another component already has this title.', $errors['lines.5.title']);
    }
}
