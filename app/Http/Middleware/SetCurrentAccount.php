<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Support\CurrentAccount;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mirrors Filament's tenant into CurrentAccount for the request.
 *
 * Filament scopes the queries it builds itself, but a widget, an action or
 * a service called from a page can query a model directly -- and with no
 * account in context the global scope is a deliberate no-op, which is to
 * say it would return every account's rows. This closes that gap by making
 * one setting cover both mechanisms.
 *
 * It is cleared on the way out because CurrentAccount is static, and in a
 * long-lived worker a leftover account would follow the next request.
 */
class SetCurrentAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        CurrentAccount::set(
            $tenant instanceof Account && ! $tenant->is_suspended ? $tenant : null,
        );

        try {
            return $next($request);
        } finally {
            CurrentAccount::clear();
        }
    }
}
