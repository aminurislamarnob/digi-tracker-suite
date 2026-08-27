<?php

namespace App\Http\Requests\Ingest\Concerns;

use App\Models\Project;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolve the project -- and therefore the account -- from the hash.
 *
 * The hash is the only routing key the protocol offers and it is visible
 * in GPL source, so it is treated as a claim rather than a credential.
 * What matters is where the account comes from: the project record, never
 * anything the caller sent, so a forged payload cannot select a tenant.
 *
 * Deliberately uncached. `hash` is a unique index, the workload is a few
 * hundred heartbeats a day, and a cache here bought us nothing while
 * costing two real things: a project switched off by mistake would keep
 * ingesting for the length of the TTL, and caching an Eloquent model
 * through the database store silently corrupts it -- MySQL drops the NUL
 * bytes that mark protected properties, and the value unserialises as
 * __PHP_Incomplete_Class. If this ever needs caching, cache the id.
 */
trait ResolvesProjectByHash
{
    protected ?Project $resolvedProject = null;

    public function project(): Project
    {
        if ($this->resolvedProject) {
            return $this->resolvedProject;
        }

        $project = Project::acrossAccounts()
            ->where('hash', (string) $this->input('hash'))
            ->where('is_active', true)
            // A demo project's numbers are invented. Letting one real site
            // report into it would make the whole project ambiguous: nobody
            // could say afterwards which rows had been measured.
            ->where('is_demo', false)
            ->first();

        if (! $project) {
            throw new NotFoundHttpException('Unknown project.');
        }

        return $this->resolvedProject = $project;
    }
}
