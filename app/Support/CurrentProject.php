<?php

namespace App\Support;

use App\Models\Project;
use Illuminate\Support\Facades\Request;

/**
 * The project the current page is about.
 *
 * Read from the route rather than held in the session: every analytics
 * surface answers a question about one plugin, so the project belongs in
 * the URL. That makes every screen linkable and bookmarkable, and it means
 * two browser tabs can sit on two different projects without one of them
 * silently changing under the other.
 */
class CurrentProject
{
    public static function get(): ?Project
    {
        $project = Request::route('project');

        return $project instanceof Project ? $project : null;
    }
}
