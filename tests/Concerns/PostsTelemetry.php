<?php

namespace Tests\Concerns;

use App\Models\Project;
use Illuminate\Testing\TestResponse;

/**
 * Posts payloads the way the SDK actually posts them.
 *
 * Form-encoded, not JSON, with nested values flattened by
 * http_build_query on the way out -- which is where every trap in this
 * protocol comes from. A JSON fixture would pass tests the real wire
 * format fails.
 */
trait PostsTelemetry
{
    protected function payload(Project $project, array $overrides = []): array
    {
        return array_replace_recursive([
            'hash' => $project->hash,
            'url' => 'https://example.com',
            'site' => 'Example Site',
            'admin_email' => 'owner@example.com',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'project_version' => '2.2.4',
            'server' => [
                'software' => 'nginx/1.24.0',
                'php_version' => '8.2.4',
                'mysql_version' => '8.0.36',
            ],
            'wp' => [
                'version' => '6.8',
                'locale' => 'en_US',
                'memory_limit' => '256M',
                'debug_mode' => 'No',
                'multisite' => 'No',
                'theme_slug' => 'twentytwentyfive',
                'theme_name' => 'Twenty Twenty-Five',
                'theme_version' => '1.2',
            ],
            'users' => ['total' => 5, 'administrator' => 2, 'editor' => 3],
            'active_plugins' => 14,
            'inactive_plugins' => 3,
            'is_local' => '',
            'tracking_skipped' => '',
            'client' => '2.0.4',
        ], $overrides);
    }

    protected function postTelemetry(string $route, array $payload): TestResponse
    {
        return $this->call('POST', $route, $payload, server: [
            'HTTP_USER_AGENT' => 'Appsero/'.md5($payload['url'] ?? 'https://example.com').';',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ]);
    }

    protected function track(Project $project, array $overrides = []): TestResponse
    {
        return $this->postTelemetry('/track', $this->payload($project, $overrides));
    }

    protected function deactivate(Project $project, array $overrides = []): TestResponse
    {
        return $this->postTelemetry('/deactivate', $this->payload($project, $overrides));
    }
}
