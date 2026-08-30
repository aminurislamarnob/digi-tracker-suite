<?php

namespace App\Filament\Widgets;

use App\Services\AccountOverview;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Every project, one line each, ours beside the public record.
 *
 * The pairing is the point. Tracked installs on their own invite the
 * question "out of how many", and the answer is in the next column --
 * which is also the only place on the dashboard where the opt-in rate is
 * visible per project rather than as one blended figure.
 */
class AccountProjectsTable extends Widget
{
    protected string $view = 'filament.widgets.account-projects-table';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 4;

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getRows(): Collection
    {
        return app(AccountOverview::class)->projects();
    }
}
