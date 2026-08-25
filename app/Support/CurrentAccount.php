<?php

namespace App\Support;

use App\Models\Account;

/**
 * Holds the account in context for the current request or job.
 *
 * Kept as an explicit container rather than reading auth()->user() inside
 * the global scope, because queues, console commands and ingest all run
 * without a logged-in user but still need a defined tenancy context.
 */
class CurrentAccount
{
    protected static ?Account $account = null;

    public static function set(?Account $account): void
    {
        static::$account = $account;
    }

    public static function get(): ?Account
    {
        return static::$account;
    }

    public static function id(): ?int
    {
        return static::$account?->id;
    }

    public static function clear(): void
    {
        static::$account = null;
    }

    /**
     * Run a callback with no account in context, restoring it afterwards.
     * For ingest and rollups, which legitimately span accounts.
     */
    public static function withoutScope(callable $callback): mixed
    {
        $previous = static::$account;
        static::$account = null;

        try {
            return $callback();
        } finally {
            static::$account = $previous;
        }
    }
}
