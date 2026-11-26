<?php

namespace App\Support\Payroll;

/**
 * Checks a pay scale's components as the page sends them, before they are previewed or saved:
 * every title is there and different (and is not "Grade", which means the grade number), every
 * formula reads, uses only [Grade] and the other components, and no component ends up using
 * itself (Basic from Gross and Gross from Basic could never be worked out).
 */
final class PayScaleCheck
{
    /**
     * @param  list<array<string, mixed>>  $rows  [{title, formula}, ...] in order
     * @return array<string, string> message by field, e.g. "lines.2.formula"
     */
    public static function errors(array $rows): array
    {
        $errors = [];
        $index = []; // formula key => row number
        $titles = [];

        foreach ($rows as $i => $row) {
            $title = trim((string) ($row['title'] ?? ''));
            $key = Formula::key($title);
            $titles[$i] = $title;

            if ($title === '') {
                $errors["lines.{$i}.title"] = 'Give the component a title, e.g. Basic.';
            } elseif (str_contains($title, '[') || str_contains($title, ']')) {
                $errors["lines.{$i}.title"] = 'A title cannot have [ or ] in it.';
            } elseif ($key === Formula::GRADE) {
                $errors["lines.{$i}.title"] = '[Grade] is the grade number itself. Give the component another title.';
            } elseif (isset($index[$key])) {
                $errors["lines.{$i}.title"] = 'Another component already has this title.';
            } else {
                $index[$key] = $i;
            }
        }

        $uses = [];
        foreach ($rows as $i => $row) {
            try {
                $formula = Formula::parse((string) ($row['formula'] ?? ''));
            } catch (FormulaException $e) {
                $errors["lines.{$i}.formula"] = $e->getMessage();

                continue;
            }

            $unknown = array_diff_key($formula->referenceNames(), $index, [Formula::GRADE => true]);
            if ($unknown) {
                $choices = implode(', ', array_map(fn ($t) => "[{$t}]", ['Grade', ...array_values(array_filter(array_intersect_key($titles, array_flip($index))))]));
                $errors["lines.{$i}.formula"] = 'There is no component called ['.reset($unknown)."]. You can use {$choices}.";

                continue;
            }

            $uses[$i] = array_values(array_map(fn ($key) => $index[$key], array_diff($formula->references(), [Formula::GRADE])));
        }

        foreach (self::loops($uses) as $i => $loop) {
            $names = array_map(fn ($n) => "[{$titles[$n]}]", $loop);
            $errors["lines.{$i}.formula"] ??= count($loop) === 1
                ? "{$names[0]} uses itself, so it can never be worked out."
                : implode(' and ', [implode(', ', array_slice($names, 0, -1)), end($names)]).' use each other, so none of them can be worked out.';
        }

        return $errors;
    }

    /**
     * The rows caught in a loop, each with the rows of its loop.
     *
     * @param  array<int, list<int>>  $uses  row => rows its formula uses
     * @return array<int, list<int>>
     */
    private static function loops(array $uses): array
    {
        $state = [];   // row => 1 while being followed, 2 when done
        $stack = [];
        $found = [];

        $follow = function (int $row) use (&$follow, &$state, &$stack, &$found, $uses): void {
            $state[$row] = 1;
            $stack[] = $row;

            foreach ($uses[$row] ?? [] as $next) {
                if (($state[$next] ?? 0) === 1) {
                    $loop = array_slice($stack, array_search($next, $stack, true));
                    foreach ($loop as $member) {
                        $found[$member] ??= $loop;
                    }
                } elseif (($state[$next] ?? 0) === 0) {
                    $follow($next);
                }
            }

            array_pop($stack);
            $state[$row] = 2;
        };

        foreach (array_keys($uses) as $row) {
            if (($state[$row] ?? 0) === 0) {
                $follow($row);
            }
        }

        ksort($found);

        return $found;
    }
}
