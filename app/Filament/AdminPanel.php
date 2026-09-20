<?php

namespace App\Filament;

use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * The panel, with its auth pages at the root rather than under /admin.
 *
 * Every place Filament sends somebody to sign in, sign up or reset a
 * password -- the guard on each panel page, the links between the auth
 * pages, the return after logout, the link in a reset mail -- asks the
 * panel through these four methods, and the stock answers are the routes
 * Filament registers under the panel path. That prefix cannot be changed,
 * only the slugs beneath it, so the addresses are changed where they are
 * asked for instead: this panel answers with the root routes that
 * routes/web.php mounts. Filament's own copies under /admin stay
 * registered and forward there -- see ForwardPanelAuthRoutes.
 */
class AdminPanel extends Panel
{
    /**
     * @param  array<mixed>  $parameters
     */
    public function getLoginUrl(array $parameters = []): ?string
    {
        if (! $this->hasLogin()) {
            return null;
        }

        return route('login', $parameters);
    }

    /**
     * @param  array<mixed>  $parameters
     */
    public function getRegistrationUrl(array $parameters = []): ?string
    {
        if (! $this->hasRegistration()) {
            return null;
        }

        return route('register', $parameters);
    }

    /**
     * @param  array<mixed>  $parameters
     */
    public function getRequestPasswordResetUrl(array $parameters = []): ?string
    {
        if (! $this->hasPasswordReset()) {
            return null;
        }

        return route('password.request', $parameters);
    }

    /**
     * Signed, exactly as Filament signs its own: the signature is what
     * makes a reset link usable only by whoever was sent it.
     *
     * @param  array<mixed>  $parameters
     */
    public function getResetPasswordUrl(string $token, CanResetPassword|Model|Authenticatable $user, array $parameters = []): string
    {
        return URL::signedRoute('password.reset', [
            'email' => $user->getEmailForPasswordReset(),
            'token' => $token,
            ...$parameters,
        ]);
    }
}
