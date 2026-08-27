<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\EmailSuppression;
use App\Support\CurrentAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Unsubscribe, without an account, a login, or a confirmation step.
 *
 * The link is signed, so it cannot be edited into somebody else's address,
 * and it carries the blind index rather than the address itself -- these
 * URLs end up in mail logs and browser history.
 *
 * GET and POST both act. RFC 8058 one-click unsubscribe is a POST made by
 * the mail client with no human present, and a GET that only shows a
 * "click here to confirm" button is the pattern that makes people reach
 * for the spam button instead.
 */
class UnsubscribeController extends Controller
{
    public function __invoke(Request $request, int $account): Response
    {
        $token = (string) $request->query('token');

        // The signature is the authorisation. An unsigned or tampered link
        // fails before this point via the `signed` middleware.
        if ($token === '') {
            abort(400);
        }

        CurrentAccount::withoutScope(function () use ($account, $token) {
            if (! Account::find($account)) {
                abort(404);
            }

            EmailSuppression::acrossAccounts()->updateOrCreate(
                ['account_id' => $account, 'email_index' => $token],
                ['reason' => EmailSuppression::UNSUBSCRIBED],
            );
        });

        /*
         * Plain text, no layout, no tracking pixel. Somebody who just asked
         * to stop hearing from us should not be handed a marketing page.
         */
        return response(
            "You've been unsubscribed.\n\nWe won't email you again.",
            200,
            ['Content-Type' => 'text/plain; charset=utf-8'],
        );
    }
}
