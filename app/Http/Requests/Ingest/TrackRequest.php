<?php

namespace App\Http\Requests\Ingest;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Validates a heartbeat.
 *
 * Two things about this payload are easy to get wrong, and both come from
 * it arriving as application/x-www-form-urlencoded rather than JSON:
 *
 * 1. Booleans do not survive. wp_remote_post runs the body through
 *    http_build_query, so true becomes "1" and false becomes an EMPTY
 *    STRING. A `boolean` validation rule rejects the empty string, which
 *    means a perfectly valid "this is not a local site" payload fails.
 *    Never validate these as booleans; read them through the accessors.
 *
 * 2. Nested data arrives flattened as server[php_version]=8.2. Laravel
 *    unflattens it back into arrays, but the rules must say `array`.
 */
class TrackRequest extends FormRequest
{
    protected ?Project $resolvedProject = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'hash' => ['required', 'uuid'],
            'url' => ['required', 'string', 'max:255'],
            'site' => ['nullable', 'string', 'max:255'],
            'admin_email' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'project_version' => ['required', 'string', 'max:32'],
            'server' => ['nullable', 'array'],
            'wp' => ['nullable', 'array'],
            'users' => ['nullable', 'array'],
            'plugins' => ['nullable', 'array'],
            'extra' => ['nullable', 'array'],
            'ip_address' => ['nullable', 'string', 'max:45'],
            'client' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * Resolve the project -- and therefore the account -- from the hash.
     *
     * The hash is the only routing key the protocol offers and it is
     * visible in GPL source, so it is treated as a claim. Crucially the
     * account is taken from the project record here, never from anything
     * the caller sent, so a forged payload cannot select a tenant.
     */
    public function project(): Project
    {
        if ($this->resolvedProject) {
            return $this->resolvedProject;
        }

        $hash = (string) $this->input('hash');

        $project = Cache::remember(
            "project:hash:{$hash}",
            now()->addMinutes(10),
            fn () => Project::acrossAccounts()->where('hash', $hash)->where('is_active', true)->first(),
        );

        if (! $project) {
            Cache::forget("project:hash:{$hash}");

            throw new NotFoundHttpException('Unknown project.');
        }

        return $this->resolvedProject = $project;
    }

    public function isLocal(): bool
    {
        return filter_var($this->input('is_local'), FILTER_VALIDATE_BOOL);
    }

    public function trackingSkipped(): bool
    {
        return filter_var($this->input('tracking_skipped'), FILTER_VALIDATE_BOOL);
    }
}
