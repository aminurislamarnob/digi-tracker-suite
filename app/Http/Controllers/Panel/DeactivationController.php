<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Deactivation;
use App\Models\DeactivationReason;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Churn, and the only feedback this platform ever receives in the user's
 * own words. Every other screen is a count.
 */
class DeactivationController extends Controller
{
    public function index(Request $request, Project $project): View
    {
        $reasons = DeactivationReason::query()
            ->where('project_id', $project->id)
            ->orderBy('sort_order')
            ->pluck('label', 'reason_id');

        $reasonId = $request->query('reason');

        $deactivations = Deactivation::query()
            ->where('project_id', $project->id)
            ->when($reasonId, fn ($query, $value) => $query->where('reason_id', $value))
            ->when($request->boolean('with_comment'), fn ($query) => $query->whereNotNull('reason_info'))
            ->with('site:id,canonical_url')
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('pages.projects.deactivations', [
            'title' => $project->name.' · Deactivations',
            'project' => $project,
            'deactivations' => $deactivations,
            'reasons' => $reasons,
            'reasonId' => $reasonId,
        ]);
    }
}
