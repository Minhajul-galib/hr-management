<?php

namespace App\Support\MasterData;

/**
 * Proposes codes that are not taken yet, following the numbering people
 * already use: when 101 … 140 exist, a duplicate 104 gets 141; d004 gets d005.
 * A code needs a number, so a word like "hr" becomes hr1, hr2, … .
 *
 * Codes count as taken when they are saved (looked up per prefix, in the
 * database) or claimed by a row of the file being imported, and every code it
 * proposes is claimed too, so two rows are never offered the same one.
 * A scope (other columns a code is unique within, if a master has any) keeps each series separate.
 */
final class CodeSuggester
{
    private const SERIES = '/^(.*?)(\d+)$/';

    /** @var array<string, array<string, true>> scope key => taken codes */
    private array $taken = [];

    /** @var array<string, true> scope key + prefix already read from the database */
    private array $loaded = [];

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  bool  $unitFormat  codes like F02-PDD (see Code::UNIT_PATTERN) instead of numbers and small letters
     *
     * Codes are compared ignoring case, so F02-PDD and f02-pdd are the same code.
     */
    public function __construct(
        private readonly string $model,
        private readonly string $column = 'code',
        private readonly bool $unitFormat = false,
    ) {}

    /** @param  array<string, mixed>  $scope */
    public function claim(string $code, array $scope = []): void
    {
        $this->taken[$this->scopeKey($scope)][strtolower($code)] = true;
    }

    /**
     * The code itself, made valid (small letters and numbers only, with at
     * least one number) when that is free, otherwise the next free one in its series.
     *
     * @param  array<string, mixed>  $scope
     */
    public function free(string $code, array $scope = []): ?string
    {
        $code = $this->tidy($code);
        if ($code === '') {
            return null;
        }

        if (! preg_match('/\d/', $code)) {
            return $this->next($code, $scope); // "hr" → hr1
        }

        $taken = $this->codesStartingWith($code, $scope);
        if (! isset($taken[strtolower($code)])) {
            $this->claim($code, $scope);

            return $code;
        }

        return $this->next($code, $scope);
    }

    /**
     * The next free code after this one in its series.
     *
     * @param  array<string, mixed>  $scope
     */
    public function next(string $code, array $scope = []): ?string
    {
        $code = $this->tidy($code);
        if ($code === '') {
            return null;
        }

        if (preg_match(self::SERIES, $code, $m)) {
            [$prefix, $width, $highest] = [$m[1], strlen($m[2]), (int) $m[2]];
        } else {
            [$prefix, $width, $highest] = [$code, 1, 0];
        }

        $taken = $this->codesStartingWith($prefix, $scope);
        $pattern = '/^'.preg_quote($prefix, '/').'(\d+)$/i';
        foreach (array_keys($taken) as $existing) {
            if (preg_match($pattern, (string) $existing, $n)) {
                $highest = max($highest, (int) $n[1]);
            }
        }

        $number = $highest + 1;
        do {
            $candidate = $prefix.str_pad((string) $number++, $width, '0', STR_PAD_LEFT);
        } while (isset($taken[strtolower($candidate)]));

        if (strlen($candidate) > Code::MAX_LENGTH) {
            return null;
        }

        $this->claim($candidate, $scope);

        return $candidate;
    }

    /** Small letters and numbers only. */
    public static function clean(string $code): string
    {
        return Code::clean($code);
    }

    /** The code made valid in this suggester's format. */
    private function tidy(string $code): string
    {
        return $this->unitFormat ? Code::cleanUnit($code) : self::clean($code);
    }

    /**
     * Every taken code in the scope, after reading the saved ones that start with $prefix.
     *
     * @param  array<string, mixed>  $scope
     * @return array<string, true>
     */
    private function codesStartingWith(string $prefix, array $scope): array
    {
        $key = $this->scopeKey($scope);
        $this->taken[$key] ??= [];

        $prefix = strtolower($prefix);

        if (! isset($this->loaded[$key."\x1F".$prefix])) {
            $this->loaded[$key."\x1F".$prefix] = true;

            $query = $this->model::query()->where($scope);
            if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($this->model), true)) {
                $query->withTrashed(); // a code in the Trash is still taken
            }
            $saved = $query
                ->whereRaw('LOWER('.$query->getQuery()->getGrammar()->wrap($this->column).') like ?', [addcslashes($prefix, '\\%_').'%'])
                ->pluck($this->column);

            foreach ($saved as $code) {
                $this->taken[$key][strtolower((string) $code)] = true;
            }
        }

        return $this->taken[$key];
    }

    /** @param  array<string, mixed>  $scope */
    private function scopeKey(array $scope): string
    {
        ksort($scope);

        return json_encode($scope) ?: '';
    }
}
