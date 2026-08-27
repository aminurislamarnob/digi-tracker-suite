<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\DashboardMetrics;
use Illuminate\View\View;

/**
 * The landing page: every plugin in the account, with the one number that
 * matters next to it.
 *
 * Scoping is implicit -- Project carries the account global scope -- so
 * this query cannot return another account's plugins even if someone
 * forgets a where clause here.
 */
class ProjectController extends Controller
{
    public function index(DashboardMetrics $metrics): View
    {
        $projects = Project::orderBy('name')->get();

        return view('pages.projects.index', [
            'title' => 'Projects',
            'projects' => $projects->map(fn (Project $project) => [
                'project' => $project,
                'latest' => $metrics->latest($project),
            ]),
        ]);
    }
}
