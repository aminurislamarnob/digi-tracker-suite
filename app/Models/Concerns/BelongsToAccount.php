<?php

namespace App\Models\Concerns;

use App\Models\Account;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The tenancy boundary.
 *
 * Every tenant-owned model uses this. It scopes reads to the account in
 * context and stamps account_id on create, so isolation is the default
 * rather than something each query has to remember.
 *
 * Ingest runs with no account in context -- the payload arrives before we
 * know whose it is -- so when CurrentAccount is unset the scope is a no-op
 * and account_id must be set explicitly. That is why the ingest path always
 * reads it off the resolved project rather than off anything in the body.
 */
trait BelongsToAccount
{
    public static function bootBelongsToAccount(): void
    {
        static::addGlobalScope('account', function (Builder $query) {
            if ($accountId = CurrentAccount::id()) {
                $query->where($query->getModel()->getTable().'.account_id', $accountId);
            }
        });

        static::creating(function ($model) {
            if (! $model->account_id && $accountId = CurrentAccount::id()) {
                $model->account_id = $accountId;
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Escape hatch for jobs, console commands and the platform admin panel.
     * Use it deliberately and never in response to a web request.
     */
    public function scopeAcrossAccounts(Builder $query): Builder
    {
        return $query->withoutGlobalScope('account');
    }
}
