<?php

namespace App\Filament\Widgets;

use App\Services\AccountOverview;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * The short list of things worth doing something about.
 *
 * Deliberately not a health score. A number out of a hundred compresses
 * "one plugin has never been captured" and "one rollup is a day late" into
 * the same 92%, and then nobody acts on either. Each row here names one
 * specific thing and links to the page where it can be fixed.
 *
 * It renders nothing at all when the list is empty. An all-clear panel is
 * a thing to scroll past on every visit for the sake of the rare day it
 * says something.
 */
class ProjectsNeedingAttention extends Widget
{
    protected string $view = 'filament.widgets.projects-needing-attention';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return app(AccountOverview::class)->attention()->isNotEmpty();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getFlags(): Collection
    {
        return app(AccountOverview::class)->attention();
    }
}
