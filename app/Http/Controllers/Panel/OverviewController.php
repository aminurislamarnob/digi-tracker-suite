<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\DeactivationReason;
use App\Models\Project;
use App\Models\Site;
use App\Services\DashboardMetrics;
use Illuminate\View\View;

class OverviewController extends Controller
{
    public function __invoke(Project $project, DashboardMetrics $metrics): View
    {
        $latest = $metrics->latest($project);

        return view('pages.projects.overview', [
            'title' => $project->name.' · Overview',
            'project' => $project,
            'latest' => $latest,
            'headline' => $metrics->headline($project),
            'statusCounts' => $this->statusCounts($project),
            'reasonCounts' => $this->reasonCounts($project),
            'donuts' => [
                'WordPress' => $metrics->distribution($project, 'by_wp'),
                'PHP' => $metrics->distribution($project, 'by_php'),
                'MySQL' => $metrics->distribution($project, 'by_mysql'),
                'Server' => $metrics->distribution($project, 'by_server'),
                'Locale' => $metrics->distribution($project, 'by_locale'),
                'Plugin version' => $metrics->distribution($project, 'by_version'),
                'Multisite' => $metrics->distribution($project, 'by_multisite'),
                'Country' => $metrics->distribution($project, 'by_country'),
            ],
        ]);
    }

    /**
     * Read from `sites` rather than the rollup, and only here.
     *
     * This is the one panel that is explicitly about right now -- "what is
     * the state of the fleet today" -- so a nightly figure would be stale
     * by design. It is three indexed counts, not a JSON aggregate.
     */
    protected function statusCounts(Project $project): array
    {
        $counts = Site::query()
            ->where('project_id', $project->id)
            ->where('is_local', false)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'Active' => (int) $counts->get(Site::STATUS_ACTIVE, 0),
            'Inactive' => (int) $counts->get(Site::STATUS_INACTIVE, 0),
            'Deactivated' => (int) $counts->get(Site::STATUS_DEACTIVATED, 0),
        ];
    }

    /**
     * Reasons are labelled through the project's own list, because a
     * reason_id is only unique per project and an author may have reworded
     * or removed one since it was recorded.
     */
    protected function reasonCounts(Project $project): array
    {
        $labels = DeactivationReason::query()
            ->where('project_id', $project->id)
            ->pluck('label', 'reason_id');

        return $project->deactivations()
            ->selectRaw('reason_id, COUNT(*) as total')
            ->groupBy('reason_id')
            ->pluck('total', 'reason_id')
            ->mapWithKeys(fn ($total, $id) => [
                $labels[$id] ?? ($id ?: 'Not given') => (int) $total,
            ])
            ->sortDesc()
            ->all();
    }
}
