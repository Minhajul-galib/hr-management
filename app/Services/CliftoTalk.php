<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Static helpers that keep Clifto Talk portable between applications.
 *
 * Everything here exists because the module has to work in a host app whose
 * database driver, permission package and route prefix are not known upfront.
 */
class CliftoTalk
{
    public static function prefix(): string
    {
        return trim((string) config('clifto-talk.route_prefix', 'clifto-talk'), '/');
    }

    public static function brand(): string
    {
        return (string) config('clifto-talk.brand', 'Clifto Talk');
    }

    /** Route-name prefix, derived from the URL prefix so the two never drift. */
    public static function routeName(string $name): string
    {
        return self::prefix().'.'.$name;
    }

    /**
     * Middleware for the module's route group, including the permission check
     * only when the host app has configured one.
     */
    public static function middleware(): array
    {
        $middleware = (array) config('clifto-talk.middleware', ['auth']);

        if ($permission = config('clifto-talk.permission')) {
            $middleware[] = 'permission:'.$permission;
        }

        return $middleware;
    }

    /**
     * A null permission means "any authenticated user", which is what a host
     * app without a permission package needs.
     */
    public static function allows(?object $user): bool
    {
        if (! $user) {
            return false;
        }

        $permission = config('clifto-talk.permission');

        if (! $permission) {
            return true;
        }

        return method_exists($user, 'can') && $user->can($permission);
    }

    /**
     * Case-insensitive LIKE for the current connection.
     *
     * Postgres needs ILIKE; MySQL and SQLite are already case-insensitive for
     * the default collations, so plain LIKE is correct there.
     */
    public static function likeOperator(?string $connection = null): string
    {
        return DB::connection($connection)->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    /** Escapes the wildcards a user may legitimately type into a search box. */
    public static function likeTerm(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
    }

    /** Applies a case-insensitive OR-search across the given columns. */
    public static function applySearch(Builder $query, string $term, array $columns): Builder
    {
        $operator = self::likeOperator();
        $value = self::likeTerm($term);

        return $query->where(function (Builder $inner) use ($columns, $operator, $value) {
            foreach ($columns as $column) {
                $inner->orWhere($column, $operator, $value);
            }
        });
    }

    public static function attachmentDisk(): string
    {
        return (string) config('clifto-talk.attachments.disk', 'local');
    }

    public static function avatarDisk(): string
    {
        return (string) config('clifto-talk.avatar_disk', 'public');
    }

    /** The optional relation shown under a person's name in the directory. */
    public static function subtitleFor(object $user): ?string
    {
        $relation = config('clifto-talk.directory.subtitle_relation');

        if (! $relation || ! method_exists($user, $relation)) {
            return null;
        }

        $related = $user->{$relation};
        $column = config('clifto-talk.directory.subtitle_column', 'name');

        return $related?->{$column} ?? null;
    }
}
