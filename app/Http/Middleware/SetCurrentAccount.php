<?php

namespace App\Http\Middleware;

use App\Support\CurrentAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the signed-in user's account into context for the whole request.
 *
 * This is what makes the global scope on every telemetry model do its job:
 * without an account set the scope is a deliberate no-op, so a dashboard
 * route that skipped this middleware would quietly return every account's
 * rows. It runs on the panel group as a whole rather than per controller
 * for exactly that reason -- opting in per route is one forgotten line
 * away from a data leak.
 *
 * It is cleared on the way out because CurrentAccount is static, and in a
 * long-lived worker a leftover account would follow the next request.
 */
class SetCurrentAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user()?->resolveCurrentAccount();

        if ($account?->is_suspended) {
            $account = null;
        }

        CurrentAccount::set($account);

        try {
            return $next($request);
        } finally {
            CurrentAccount::clear();
        }
    }
}
