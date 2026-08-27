<?php

namespace App\Http\Controllers\Ingest;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ingest\DeactivateRequest;
use App\Jobs\ProcessTrackingPayload;
use App\Models\RawPayload;
use Illuminate\Http\JsonResponse;

/**
 * The user deactivated the plugin and told us why.
 *
 * This is the single most valuable request this platform receives -- it is
 * the only one carrying words a person wrote -- and it is also the one
 * most likely to be lost, because the browser is mid-deactivation and the
 * SDK fires it without waiting. So the write is durable before anything
 * is interpreted, exactly as with a heartbeat.
 */
class DeactivateController extends Controller
{
    public function __invoke(DeactivateRequest $request): JsonResponse
    {
        $project = $request->project();

        $payload = RawPayload::create([
            'account_id' => $project->account_id,
            'project_id' => $project->id,
            'route' => RawPayload::ROUTE_DEACTIVATE,
            'payload' => $request->except('hash'),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        ProcessTrackingPayload::dispatch($payload->id);

        return response()->json(['success' => true]);
    }
}
