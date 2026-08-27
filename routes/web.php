<?php

use App\Http\Controllers\UnsubscribeController;
use Illuminate\Support\Facades\Route;

/*
 * There is no marketing site here, and the ingest endpoints deliberately
 * live at bare paths, so the root is just a signpost to the panel.
 */
Route::redirect('/', '/admin');

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
