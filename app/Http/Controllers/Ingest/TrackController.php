<?php

namespace App\Http\Controllers\Ingest;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ingest\TrackRequest;
use App\Jobs\ProcessTrackingPayload;
use App\Models\RawPayload;
use Illuminate\Http\JsonResponse;

/**
 * Receives the weekly heartbeat from an installed plugin.
 *
 * The SDK sends this non-blocking, so the site never reads our response.
 * Everything here exists to get the payload durably onto disk and hand
 * back a 200 as fast as possible; all interpretation happens in the job.
 */
class TrackController extends Controller
{
    public function __invoke(TrackRequest $request): JsonResponse
    {
        $project = $request->project();

        $payload = RawPayload::create([
            'account_id' => $project->account_id,
            'project_id' => $project->id,
            'route' => 'track',
            'payload' => $request->except('hash'),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        ProcessTrackingPayload::dispatch($payload->id);

        return response()->json(['success' => true]);
    }
}
