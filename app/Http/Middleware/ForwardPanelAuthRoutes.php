<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends /admin/login, /admin/register and /admin/password-reset/request
 * to their addresses at the root.
 *
 * Filament registers those three under the panel path and offers no way
 * to stop it short of switching the feature off, so they stay routed --
 * and anyone who reaches one, from an old bookmark or a link in a mail,
 * is forwarded to the address the panel now gives out (App\Filament\
 * AdminPanel). Runs on every panel request, which is three route-name
 * checks; it acts on none but those.
 *
 * Not the reset page. Its links are signed for the exact URL that was
 * mailed, and a mail sent before this change carries the old one -- a
 * forward would break it, and the mail is good for an hour. New mails
 * carry the new address. The old route is simply left to answer.
 */
class ForwardPanelAuthRoutes
{
    public function handle(Request $request, Closure $next): Response
    {
        $panel = Filament::getCurrentOrDefaultPanel();

        $forwards = [
            $panel->generateRouteName('auth.login') => $panel->getLoginUrl(),
            $panel->generateRouteName('auth.register') => $panel->getRegistrationUrl(),
            $panel->generateRouteName('auth.password-reset.request') => $panel->getRequestPasswordResetUrl(),
        ];

        foreach ($forwards as $route => $to) {
            if ($to !== null && $request->routeIs($route)) {
                return redirect()->to($to);
            }
        }

        return $next($request);
    }
}
