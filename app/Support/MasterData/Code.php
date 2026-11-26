<?php

namespace App\Support\MasterData;

/**
 * The format of every master-data code (department, designation, salary type,
 * grade, leave type, offence type, document type, unit): a unique id made of
 * numbers and small letters only, with at least one number — 101, 652, d001.
 *
 * Other records are linked to a master by its code, so it has one definition
 * here for the forms, the Excel imports and the database clean-up to share.
 */
final class Code
{
    public const MAX_LENGTH = 50;

    /** Small letters and numbers only, and at least one number. (`\z`, not `$`, which would let a trailing newline through.) */
    public const PATTERN = '/^(?=.*\d)[a-z0-9]+\z/';

    public const MESSAGE = 'The code may only contain numbers and small letters, and must have at least one number (for example 101 or d001).';

    /**
     * A unit's code is looser, because factories already number their units like
     * F02-PDD: letters (capitals or small), numbers and single hyphens between
     * them, with at least one number. Capitals are kept as typed.
     */
    public const UNIT_PATTERN = '/^(?=.*\d)[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*\z/';

    public const UNIT_MESSAGE = 'The unit code may only contain letters, numbers and hyphens, and must have at least one number (for example 101 or F02-PDD).';

    public static function isValid(string $code): bool
    {
        return strlen($code) <= self::MAX_LENGTH && preg_match(self::PATTERN, $code) === 1;
    }

    public static function isValidUnit(string $code): bool
    {
        return strlen($code) <= self::MAX_LENGTH && preg_match(self::UNIT_PATTERN, $code) === 1;
    }

    /**
     * What was typed, ready to validate: trimmed, with capitals made small (unless
     * `$keepCase`, as unit codes do). A code that Excel stored as a number (101 or
     * 101.0) becomes its text. Anything else is left for the validator to reject,
     * so a mistake is reported, not hidden.
     */
    public static function normalise(mixed $value, bool $keepCase = false): mixed
    {
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            $value = (int) $value;
        }

        return match (true) {
            is_int($value) => (string) $value,
            is_string($value) => $keepCase ? trim($value) : strtolower(trim($value)),
            default => $value,
        };
    }

    /** The closest valid spelling: small letters and numbers, nothing else. May still lack a number. */
    public static function clean(string $code): string
    {
        return substr(preg_replace('/[^a-z0-9]+/', '', strtolower($code)) ?? '', 0, self::MAX_LENGTH);
    }

    /**
     * The closest valid unit code: letters and numbers kept as typed, any run of other
     * symbols or spaces turned into one hyphen (F01_PDD → F01-PDD). May still lack a number.
     */
    public static function cleanUnit(string $code): string
    {
        $code = trim(preg_replace('/[^A-Za-z0-9]+/', '-', trim($code)) ?? '', '-');

        return rtrim(substr($code, 0, self::MAX_LENGTH), '-');
    }
}
