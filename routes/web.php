<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Panel\DeactivationController;
use App\Http\Controllers\Panel\EndUserController;
use App\Http\Controllers\Panel\OverviewController;
use App\Http\Controllers\Panel\ProjectController;
use App\Http\Controllers\Panel\SiteController;
use App\Models\Account;
use App\Models\Project;
use App\Support\CurrentAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * The tenancy boundary, restated at the routing layer.
 *
 * Implicit binding runs inside the web middleware group, before any route
 * middleware of ours has had a chance to set the account in context -- so
 * relying on the global scope alone here would resolve a project against
 * an empty scope, which is to say against every account. This binding
 * resolves the account itself and refuses anything outside it.
 */
Route::bind('project', function (string $slug) {
    $account = CurrentAccount::get() ?? request()->user()?->resolveCurrentAccount();

    abort_unless($account, 404);

    return Project::acrossAccounts()
        ->where('account_id', $account->id)
        ->where('slug', $slug)
        ->firstOrFail();
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware(['auth', 'account'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', [ProjectController::class, 'index'])->name('projects.index');

    Route::post('/accounts/{account}/switch', function (Request $request, Account $account) {
        // switchTo re-checks membership, so a guessed id changes nothing.
        $request->user()->switchTo($account);

        return redirect()->route('projects.index');
    })->name('accounts.switch');

    Route::prefix('p/{project}')->group(function () {
        Route::get('/', OverviewController::class)->name('projects.overview');
        Route::get('/sites', [SiteController::class, 'index'])->name('projects.sites');
        Route::get('/end-users', [EndUserController::class, 'index'])->name('projects.end-users');
        Route::get('/deactivations', [DeactivationController::class, 'index'])->name('projects.deactivations');
    });
});
