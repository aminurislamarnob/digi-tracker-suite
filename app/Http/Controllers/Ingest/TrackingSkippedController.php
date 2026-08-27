<?php

namespace App\Http\Controllers\Ingest;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ingest\TrackingSkippedRequest;
use App\Models\RawPayload;
use App\Models\TrackingSkip;
use Illuminate\Http\JsonResponse;

/**
 * Somebody declined the opt-in dialog.
 *
 * Written synchronously rather than queued: there is nothing to reconcile
 * -- no site, no user, no environment -- so a job would cost more than the
 * insert it defers.
 *
 * This number is the denominator that keeps the dashboard honest. Tracked
 * installs will always read far below the wordpress.org figure, and the
 * gap is the opt-in rate, not a bug.
 */
class TrackingSkippedController extends Controller
{
    public function __invoke(TrackingSkippedRequest $request): JsonResponse
    {
        $project = $request->project();

        $payload = RawPayload::create([
            'account_id' => $project->account_id,
            'project_id' => $project->id,
            'route' => RawPayload::ROUTE_TRACKING_SKIPPED,
            /*
             * Only the flag, never $request->except('hash'). A misbehaving
             * or future client could append a URL or an email to a refusal,
             * and storing it would take by inference the very thing this
             * person just declined to give.
             */
            'payload' => ['previously_skipped' => $request->previouslySkipped()],
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'processed_at' => now(),
        ]);

        TrackingSkip::create([
            'account_id' => $project->account_id,
            'project_id' => $project->id,
            'previously_skipped' => $request->previouslySkipped(),
            'ip' => $payload->ip,
        ]);

        return response()->json(['success' => true]);
    }
}
