<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\EndUser;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * People, not sites.
 *
 * Email is encrypted at rest, so it can be looked up by exact match
 * through the blind index and by nothing else. That is a deliberate
 * limit rather than a missing feature: partial search over a column of
 * contact details is the difference between answering a support ticket
 * and browsing a mailing list.
 */
class EndUserController extends Controller
{
    public function index(Request $request, Project $project): View
    {
        $email = trim((string) $request->query('email'));

        $endUsers = EndUser::query()
            ->where('project_id', $project->id)
            ->when($email !== '', fn ($query) => $query->where('email_index', EndUser::indexFor($email)))
            ->withCount('sites')
            ->orderByDesc('last_seen_at')
            ->paginate(25)
            ->withQueryString();

        return view('pages.projects.end-users', [
            'title' => $project->name.' · End users',
            'project' => $project,
            'endUsers' => $endUsers,
            'email' => $email,
        ]);
    }
}
