<?php

use App\Http\Controllers\UnsubscribeController;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

/*
 * There is no marketing site here, and the ingest endpoints deliberately
 * live at bare paths, so the root is just a signpost to the panel.
 */
Route::redirect('/', '/admin');

/*
 * The auth pages at the root: /login, /register, /password-reset/...
 *
 * Filament registers every auth route under the panel path and offers a
 * slug to change, never the prefix. So the pages are mounted a second time
 * here, at the root, with the panel's own middleware, under the names
 * Laravel itself uses for them -- and the panel gives out these addresses
 * whenever it is asked for one; see App\Filament\AdminPanel. Filament's
 * copies under /admin stay registered and forward here.
 *
 * Registration only when the panel has it: it is behind a config flag,
 * and a closed door must not have a second one at a different address.
 */
$panel = Filament::getPanel('admin');

Route::middleware($panel->getMiddleware())->group(function () use ($panel): void {
    Route::get('/login', $panel->getLoginRouteAction())->name('login');

    if ($panel->hasRegistration()) {
        Route::get('/register', $panel->getRegistrationRouteAction())->name('register');
    }

    Route::get('/password-reset/request', $panel->getRequestPasswordResetRouteAction())->name('password.request');
    Route::get('/password-reset/reset', $panel->getResetPasswordRouteAction())->middleware('signed')->name('password.reset');
});

/*
 * Unsubscribe. GET and POST both act: RFC 8058 one-click is a POST made by
 * the mail client with nobody present, and a GET that only offers a confirm
 * button is what makes people reach for the spam button instead.
 *
 * `signed` is the whole authorisation model -- there is no session here, by
 * design. Somebody who wants out should not have to make an account first.
 */
Route::match(['get', 'post'], '/unsubscribe/{account}', UnsubscribeController::class)
    ->middleware('signed')
    ->name('email.unsubscribe');
