<?php

namespace App\Jobs;

use App\Models\RawPayload;
use App\Services\SiteReconciler;
use App\Support\CurrentAccount;
use App\Support\SiteUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ProcessTrackingPayload implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $rawPayloadId) {}

    /**
     * One site's events must not interleave. Ordering matters because a
     * deactivation followed by a track is a reactivation, and the reverse
     * is churn -- getting them backwards inverts the meaning.
     */
    public function middleware(): array
    {
        $payload = RawPayload::acrossAccounts()->find($this->rawPayloadId);

        if (! $payload) {
            return [];
        }

        $url = $payload->payload['url'] ?? '';

        return [(new WithoutOverlapping(
            $payload->project_id.':'.SiteUrl::key($url)
        ))->expireAfter(180)];
    }

    public function handle(SiteReconciler $reconciler): void
    {
        $payload = RawPayload::acrossAccounts()->find($this->rawPayloadId);

        if (! $payload || $payload->processed_at) {
            return;
        }

        // Ingest legitimately spans accounts: the job knows whose payload
        // this is from the record, not from a logged-in user.
        CurrentAccount::withoutScope(function () use ($payload, $reconciler) {
            try {
                $reconciler->reconcile($payload);

                $payload->update(['processed_at' => now(), 'error' => null]);
            } catch (Throwable $e) {
                $payload->update(['error' => $e->getMessage()]);

                throw $e;
            }
        });
    }
}
