<?php

namespace App\Support;

use App\Models\User;

/**
 * Who may do what in Compliance → Internal Audit.
 *
 * Every action has its own permission, ticked one by one in the role matrix:
 * `view audits`, `create audits`, `edit audits`, `delete audits`, `complete audits`,
 * `reopen audits` and `update audit cap` (the corrective action plan). `manage audits`
 * is full access and stands in for all of them, so a role that held it before the
 * split keeps doing everything it did. Route middleware, the form request, the
 * controller and the role matrix all read the names from here, so they agree.
 */
final class AuditPermissions
{
    /** Actions whose permission is "{action} audits". */
    public const ACTIONS = ['view', 'create', 'edit', 'delete', 'complete', 'reopen'];

    /** The corrective action plan keeps the name it was created with. */
    public const CAP = 'update audit cap';

    /** Full access. */
    public const MANAGE = 'manage audits';

    /** The permission behind one action: view, create, edit, delete, complete, reopen or cap. */
    public static function permission(string $action): string
    {
        return $action === 'cap' ? self::CAP : "{$action} audits";
    }

    /**
     * The permissions that let someone do $action: its own, or full access.
     *
     * @return list<string>
     */
    public static function allowing(string $action): array
    {
        return [self::permission($action), self::MANAGE];
    }

    /** Route middleware letting through anyone who may do $action. */
    public static function middleware(string $action): string
    {
        return 'permission:'.implode('|', self::allowing($action));
    }

    /** Any permission on audits at all: enough to open the module. @return list<string> */
    public static function opening(): array
    {
        return self::names();
    }

    /** Every audit permission. @return list<string> */
    public static function names(): array
    {
        return [...array_map(self::permission(...), self::ACTIONS), self::CAP, self::MANAGE];
    }

    /**
     * The permissions added when the single `manage audits` was split into actions.
     * `view audits`, `update audit cap` and `manage audits` already existed.
     *
     * @return list<string>
     */
    public static function added(): array
    {
        return array_values(array_diff(self::names(), ['view audits', self::CAP, self::MANAGE]));
    }

    public static function allows(?User $user, string $action): bool
    {
        return (bool) $user?->canAny(self::allowing($action));
    }
}
