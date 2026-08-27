<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The site list.
 *
 * Filters live in the query string rather than in session state, so a
 * filtered view is a link somebody can paste into a ticket. Everything
 * here is server-rendered: the interesting part of this product is the
 * numbers, and a table that works without JavaScript is one less thing
 * between a question and its answer.
 */
class SiteController extends Controller
{
    public function index(Request $request, Project $project): View
    {
        $filters = $request->only(['status', 'version', 'php', 'wp', 'country', 'q']);

        $sites = Site::query()
            ->where('project_id', $project->id)
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['version'] ?? null, fn ($query, $value) => $query->where('current_version', $value))
            ->when($filters['php'] ?? null, fn ($query, $value) => $query->where('php_version', 'like', $value.'%'))
            ->when($filters['wp'] ?? null, fn ($query, $value) => $query->where('wp_version', 'like', $value.'%'))
            ->when($filters['country'] ?? null, fn ($query, $value) => $query->where('country', $value))
            ->when($filters['q'] ?? null, fn ($query, $value) => $query->where('canonical_url', 'like', '%'.$value.'%'))
            ->orderByDesc('last_seen_at')
            ->paginate(25)
            ->withQueryString();

        return view('pages.projects.sites', [
            'title' => $project->name.' · Sites',
            'project' => $project,
            'sites' => $sites,
            'filters' => $filters,
            'versions' => $this->options($project, 'current_version'),
            'countries' => $this->options($project, 'country'),
        ]);
    }

    /** Only offer filter values that actually exist in this project's data. */
    protected function options(Project $project, string $column): array
    {
        return Site::query()
            ->where('project_id', $project->id)
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();
    }
}
