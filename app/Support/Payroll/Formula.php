<?php

namespace App\Support\Payroll;

/**
 * A salary formula as HR types it, Excel style:
 *
 *     [Grade] * 25 + 950        the grade number
 *     [Gross] * 60%             60% of another component
 *     [Basic] / 2
 *     2000                      a fixed amount
 *     [Basic] + [House Rent] + [Conveyance]
 *     round(max([Basic] * 10%, 500))
 *
 * Names in square brackets are other components of the same pay scale (matched ignoring case
 * and extra spaces), or [Grade]. Operators: + - * / ( ), a % after a number or bracket means
 * "per cent" (60% is 0.6). Functions: min, max, round(x) and round(x, decimals).
 *
 * It is parsed into a small tree and worked out from that; nothing is ever passed to eval.
 */
final class Formula
{
    /** The name that stands for the grade number. */
    public const GRADE = 'grade';

    public const MAX_LENGTH = 500;

    private const FUNCTIONS = ['min', 'max', 'round'];

    /** @var list<array{0: string, 1: mixed}> */
    private array $tokens = [];

    private int $at = 0;

    /** @var array<string, string> key => the name as typed */
    private array $references = [];

    /** @param array<int, mixed> $tree */
    private function __construct(private array $tree = []) {}

    /** How a name is compared: "House  Rent" and "house rent" are the same component. */
    public static function key(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /** @throws FormulaException when the text is not a formula */
    public static function parse(string $text): self
    {
        $text = trim($text);
        if ($text === '') {
            throw new FormulaException('Enter a formula, e.g. [Grade] * 25 + 950 or 2000.');
        }
        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw new FormulaException('The formula is too long ('.self::MAX_LENGTH.' characters at most).');
        }

        $formula = new self;
        $formula->tokens = self::tokenize($text);
        $formula->tree = $formula->expression();

        if ($formula->peek() === ['op', ',']) {
            throw new FormulaException('Write numbers without commas: 2000, not 2,000. A comma only separates values inside min, max or round.');
        }
        if ($formula->peek() !== null) {
            throw new FormulaException('Something is out of place near "'.self::show($formula->peek()).'". Check for a missing + - * / between two parts.');
        }

        return $formula;
    }

    /** Whether the text is a formula; the message of what is wrong otherwise. */
    public static function problem(string $text): ?string
    {
        try {
            self::parse($text);

            return null;
        } catch (FormulaException $e) {
            return $e->getMessage();
        }
    }

    /** @return list<string> the names it uses, as keys (see key()), each once */
    public function references(): array
    {
        return array_keys($this->references);
    }

    /** @return array<string, string> the names it uses: key => the name as typed */
    public function referenceNames(): array
    {
        return $this->references;
    }

    /**
     * Work it out. $value gives the amount of a name (a key, see key()), or null when that
     * cannot be worked out; then neither can this. Dividing by zero is null too.
     *
     * @param  callable(string): (float|int|null)  $value
     */
    public function evaluate(callable $value): ?float
    {
        $result = $this->run($this->tree, $value);

        return $result === null || is_nan($result) || is_infinite($result) ? null : $result;
    }

    // ----- reading ---------------------------------------------------------------

    /** @return list<array{0: string, 1: mixed}> */
    private static function tokenize(string $text): array
    {
        $text = strtr($text, ['×' => '*', '÷' => '/', '−' => '-']);
        $tokens = [];
        $length = strlen($text);

        for ($i = 0; $i < $length;) {
            $char = $text[$i];

            if (ctype_space($char)) {
                $i++;
            } elseif (ctype_digit($char) || ($char === '.' && ctype_digit($text[$i + 1] ?? ''))) {
                preg_match('/\d*\.?\d+|\d+\.?/A', $text, $m, 0, $i);
                $i += strlen($m[0]);
                $tokens[] = ['num', (float) $m[0]];
            } elseif ($char === '[') {
                $end = strpos($text, ']', $i);
                if ($end === false) {
                    throw new FormulaException('A [ has no closing ]. Names go in square brackets, e.g. [Basic].');
                }
                $name = substr($text, $i + 1, $end - $i - 1);
                if (str_contains($name, '[')) {
                    throw new FormulaException('A [ has no closing ]. Names go in square brackets, e.g. [Basic].');
                }
                if (trim($name) === '') {
                    throw new FormulaException('[ ] is empty. Put a name inside, e.g. [Basic].');
                }
                $tokens[] = ['ref', self::key($name), trim($name)];
                $i = $end + 1;
            } elseif (ctype_alpha($char)) {
                preg_match('/[A-Za-z_]\w*/A', $text, $m, 0, $i);
                $word = strtolower($m[0]);
                $i += strlen($m[0]);
                if (! in_array($word, self::FUNCTIONS, true)) {
                    throw new FormulaException("\"{$m[0]}\" is not understood. Put names in square brackets, e.g. [{$m[0]}]. Functions you can use: min, max, round.");
                }
                $tokens[] = ['fn', $word];
            } elseif (str_contains('+-*/%(),', $char)) {
                $tokens[] = ['op', $char];
                $i++;
            } elseif ($char === ']') {
                throw new FormulaException('A ] has no opening [.');
            } else {
                throw new FormulaException('"'.mb_substr(substr($text, $i), 0, 1).'" cannot be used in a formula.');
            }
        }

        return $tokens;
    }

    /** expression := term (('+' | '-') term)* */
    private function expression(): array
    {
        $node = $this->term();
        while (in_array($this->peekOp(), ['+', '-'], true)) {
            $op = $this->take()[1];
            $node = ['bin', $op, $node, $this->term()];
        }

        return $node;
    }

    /** term := unary (('*' | '/') unary)* */
    private function term(): array
    {
        $node = $this->unary();
        while (in_array($this->peekOp(), ['*', '/'], true)) {
            $op = $this->take()[1];
            $node = ['bin', $op, $node, $this->unary()];
        }

        return $node;
    }

    /** unary := ('-' | '+') unary | postfix */
    private function unary(): array
    {
        if (in_array($this->peekOp(), ['-', '+'], true)) {
            $op = $this->take()[1];
            $node = $this->unary();

            return $op === '-' ? ['neg', $node] : $node;
        }

        return $this->postfix();
    }

    /** postfix := primary '%'* */
    private function postfix(): array
    {
        $node = $this->primary();
        while ($this->peekOp() === '%') {
            $this->take();
            $node = ['pct', $node];
        }

        return $node;
    }

    /** primary := number | [name] | function '(' expression (',' expression)* ')' | '(' expression ')' */
    private function primary(): array
    {
        $token = $this->take();

        if ($token === null) {
            throw new FormulaException('The formula ends too soon. Something is missing at the end.');
        }

        switch ($token[0]) {
            case 'num':
                return ['num', $token[1]];

            case 'ref':
                $this->references[$token[1]] ??= $token[2];

                return ['ref', $token[1]];

            case 'fn':
                if ($this->peekOp() !== '(') {
                    throw new FormulaException("{$token[1]} needs brackets, e.g. {$token[1]}([Basic], 500).");
                }
                $this->take();
                $args = [$this->expression()];
                while ($this->peekOp() === ',') {
                    $this->take();
                    $args[] = $this->expression();
                }
                $this->expect(')');
                $this->checkArguments($token[1], count($args));

                return ['fn', $token[1], $args];
        }

        if ($token[1] === '(') {
            $node = $this->expression();
            $this->expect(')');

            return $node;
        }

        throw new FormulaException('Something is missing before "'.self::show($token).'".');
    }

    private function checkArguments(string $function, int $count): void
    {
        $ok = match ($function) {
            'round' => $count === 1 || $count === 2,
            default => $count >= 1,
        };

        if (! $ok) {
            throw new FormulaException('round takes a value and, if you like, how many decimals: round([Basic]) or round([Basic], 2).');
        }
    }

    private function expect(string $op): void
    {
        if ($this->peekOp() !== $op) {
            throw new FormulaException($op === ')' ? 'A ( has no closing ).' : "Expected \"{$op}\".");
        }
        $this->take();
    }

    private function peek(): ?array
    {
        return $this->tokens[$this->at] ?? null;
    }

    private function peekOp(): ?string
    {
        $token = $this->peek();

        return $token !== null && $token[0] === 'op' ? $token[1] : null;
    }

    private function take(): ?array
    {
        return $this->tokens[$this->at++] ?? null;
    }

    private static function show(array $token): string
    {
        return match ($token[0]) {
            'num' => (string) $token[1],
            'ref' => "[{$token[2]}]",
            default => (string) $token[1],
        };
    }

    // ----- working out -----------------------------------------------------------

    private function run(array $node, callable $value): ?float
    {
        switch ($node[0]) {
            case 'num':
                return (float) $node[1];

            case 'ref':
                $amount = $value($node[1]);

                return $amount === null ? null : (float) $amount;

            case 'neg':
                $v = $this->run($node[1], $value);

                return $v === null ? null : -$v;

            case 'pct':
                $v = $this->run($node[1], $value);

                return $v === null ? null : $v / 100;

            case 'bin':
                $left = $this->run($node[2], $value);
                $right = $this->run($node[3], $value);
                if ($left === null || $right === null) {
                    return null;
                }

                return match ($node[1]) {
                    '+' => $left + $right,
                    '-' => $left - $right,
                    '*' => $left * $right,
                    '/' => $right == 0.0 ? null : $left / $right,
                };

            case 'fn':
                $args = [];
                foreach ($node[2] as $arg) {
                    $v = $this->run($arg, $value);
                    if ($v === null) {
                        return null;
                    }
                    $args[] = $v;
                }

                return match ($node[1]) {
                    'min' => min($args),
                    'max' => max($args),
                    'round' => round($args[0], (int) ($args[1] ?? 0)),
                };
        }

        return null;
    }
}
