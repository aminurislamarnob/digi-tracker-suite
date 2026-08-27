<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use App\Services\RepoDownloads;
use Filament\Widgets\Widget;

/**
 * Download cadence: today, yesterday, the week, and the whole record.
 *
 * The chart underneath answers "what shape is this", which is the harder
 * question and the slower one to read. These four answer "what is it doing
 * right now", and they sit above the chart because that is the question
 * people arrive with.
 *
 * Every figure comes from wordpress.org's own summary endpoint rather than
 * from our daily table, so these four cards and the plugin's advanced page
 * agree by construction. See RepoDownloads for why that matters more than
 * deriving them ourselves would.
 */
class RepositoryDownloadsSummary extends Widget
{
    public ?Project $record = null;

    protected string $view = 'filament.widgets.repository-downloads-summary';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    public function getFigures(): array
    {
        if (! $this->record?->isOnRepository()) {
            return ['linked' => false];
        }

        return ['linked' => true] + app(RepoDownloads::class)->summary($this->record);
    }
}
