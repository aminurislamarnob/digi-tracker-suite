<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use App\Models\RepoDownload;
use App\Services\RepoAnalytics;
use App\Support\CurrentAccount;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\ChartWidget\Concerns\HasFiltersSchema;

/**
 * Downloads from wordpress.org, with the two lines that make them readable
 * and the markers that make them explicable.
 *
 * Raw daily downloads are close to unreadable on their own -- weekday
 * rhythm alone swamps the signal -- so the series carries a regression
 * trend and a smoothed baseline over it. The release markers are the part
 * that turns the chart from a picture into an answer: a dip means nothing
 * until you can see the version that shipped the day before it.
 *
 * The markers are a thin bar dataset rather than Chart.js annotations,
 * because Filament ships Chart.js without the annotation plugin and pulling
 * a second charting dependency into the panel to draw vertical lines is a
 * poor trade.
 *
 * The period control is a filters schema rather than ChartWidget's plain
 * select, because a select can only offer a fixed list and the question
 * people actually arrive with is often "what happened on the day we
 * shipped 3.1?". A schema can hold date pickers; the select could not.
 * The cost is that the chosen window is no longer legible from the control
 * itself -- it collapses to a funnel icon -- so the description states the
 * resolved window in words underneath the heading.
 */
class RepositoryDownloadsChart extends ChartWidget
{
    use HasFiltersSchema;

    /** Below this many days, a line with invisible points is a blank chart. */
    protected const SPARSE_POINTS = 30;

    /**
     * Six months. Long enough for the trend to mean something, short
     * enough that the weekday rhythm is still visible.
     */
    protected const DEFAULT_PERIOD = '180';

    public ?Project $record = null;

    protected ?string $heading = 'Downloads from wordpress.org';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '380px';

    public function filtersSchema(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('period')
                ->label('Period')
                ->options([
                    'today' => 'Today',
                    'yesterday' => 'Yesterday',
                    '7' => 'Last 7 days',
                    '30' => 'Last 30 days',
                    '90' => 'Last 90 days',
                    '180' => 'Last 6 months',
                    '365' => 'Last year',
                    '730' => 'Last 2 years',
                    'day' => 'A single day…',
                    'range' => 'A date range…',
                ])
                ->default(self::DEFAULT_PERIOD)
                ->selectablePlaceholder(false)
                ->native(false),

            /*
             * Two separate branches rather than one range with an optional
             * end. "From 4 March, to blank" has no reading that is not a
             * guess -- that day alone, or everything since -- and the guess
             * would be silent. Asking which is meant costs one extra option.
             */
            DatePicker::make('date')
                ->label('Day')
                ->maxDate(now())
                ->visible(fn (Get $get): bool => $get('period') === 'day'),

            DatePicker::make('from')
                ->label('From')
                ->maxDate(now())
                ->visible(fn (Get $get): bool => $get('period') === 'range'),

            DatePicker::make('until')
                ->label('To')
                ->maxDate(now())
                // Only a floor, never a swap: silently reordering the dates
                // would show a window nobody asked for and look correct.
                ->minDate(fn (Get $get) => $get('from'))
                ->visible(fn (Get $get): bool => $get('period') === 'range'),
        ]);
    }

    /**
     * The filter state resolved to two real dates.
     *
     * Public because it is the part worth testing: every branch here has a
     * plausible wrong answer -- an off-by-one that quietly drops today, a
     * reversed range that renders as empty rather than as a mistake -- and
     * none of them would look wrong on the chart.
     *
     * @return array{from: CarbonImmutable, until: CarbonImmutable}
     */
    public function resolveWindow(): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $filters = $this->filters ?? [];
        $period = $filters['period'] ?? self::DEFAULT_PERIOD;

        [$from, $until] = match ($period) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            'day' => $this->singleDay($filters['date'] ?? null, $today),
            'range' => $this->range($filters['from'] ?? null, $filters['until'] ?? null, $today),
            // subDays(n) rather than subDays(n - 1): "last 90 days" reading
            // as 90 rows is worth more than the fencepost.
            default => [$today->subDays(max(1, (int) $period ?: (int) self::DEFAULT_PERIOD)), $today],
        };

        return ['from' => $from, 'until' => $until];
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    protected function singleDay(?string $date, CarbonImmutable $today): array
    {
        $day = $this->parse($date) ?? $today;

        return [$day, $day];
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    protected function range(?string $from, ?string $until, CarbonImmutable $today): array
    {
        $start = $this->parse($from);
        $end = $this->parse($until);

        // A half-filled range is a form mid-edit, not an instruction. Fall
        // back to the default window rather than inventing the other end.
        if (! $start || ! $end) {
            return [$today->subDays((int) self::DEFAULT_PERIOD), $today];
        }

        // Reversed dates stay reversed, and getData() renders the empty
        // state. Swapping them would answer a question nobody asked.
        return [$start, $end];
    }

    protected function parse(?string $date): ?CarbonImmutable
    {
        if (blank($date)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($date)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    public function getDescription(): ?string
    {
        $window = $this->resolveWindow();

        $span = $window['from']->isSameDay($window['until'])
            ? $window['from']->format('j M Y')
            : $window['from']->format('j M Y').' to '.$window['until']->format('j M Y');

        return $span.'. Public download counts, not installs. The dashed line is a '
            .'smoothed baseline; vertical marks are releases. The most recent few days '
            .'are provisional -- wordpress.org revises them as mirrors report in.';
    }

    public function getEmptyStateHeading(): string
    {
        return $this->record?->isOnRepository()
            ? 'No downloads recorded in this window'
            : 'Not linked to wordpress.org';
    }

    public function getEmptyStateDescription(): ?string
    {
        if (! $this->record?->isOnRepository()) {
            return 'Set the project\'s repository slug to collect public download counts.';
        }

        return 'wordpress.org only publishes the last two years, and today\'s figure '
            .'often lands late. Try a wider window.';
    }

    protected function getData(): array
    {
        /*
         * An empty array, not ['datasets' => [], 'labels' => []]. isEmpty()
         * tests the returned array with empty(), and a shape carrying two
         * empty keys is not empty -- so the widget would render a blank
         * plot area instead of the empty state that explains it.
         */
        if (! $this->record?->isOnRepository()) {
            return [];
        }

        $window = $this->resolveWindow();

        $rows = CurrentAccount::withoutScope(fn () => RepoDownload::acrossAccounts()
            ->where('project_id', $this->record->id)
            ->whereBetween('date', [
                $window['from']->toDateString(),
                $window['until']->toDateString(),
            ])
            ->orderBy('date')
            ->get(['date', 'downloads']));

        if ($rows->isEmpty()) {
            return [];
        }

        $labels = $rows->map(fn ($row) => $row->date->toDateString())->all();
        $values = $rows->map(fn ($row) => (int) $row->downloads)->all();

        $analytics = app(RepoAnalytics::class);

        $markers = $analytics->releaseMarkers($this->record, $labels);

        /*
         * Markers are drawn to the top of the plot, so they need a height.
         * Taken from the peak rather than a round number, so a quiet plugin
         * does not get marks running off the top of its own chart.
         */
        $ceiling = max($values) ?: 1;

        $releaseBars = [];
        $releaseLabels = [];

        foreach ($labels as $label) {
            $releaseBars[] = isset($markers[$label]) ? $ceiling : null;
            $releaseLabels[] = $markers[$label]['version'] ?? null;
        }

        /*
         * A line joining invisible points disappears entirely when there is
         * only one of them, which is exactly what "Today" asks for. Short
         * windows get visible markers instead.
         */
        $sparse = count($values) < self::SPARSE_POINTS;

        $datasets = [
            [
                'type' => 'bar',
                'label' => 'Release',
                'data' => $releaseBars,
                'backgroundColor' => 'rgba(220,38,38,0.35)',
                'barThickness' => 2,
                'order' => 3,
                // Carried through so the tooltip can name the version.
                'releaseVersions' => $releaseLabels,
            ],
            [
                'type' => 'line',
                'label' => 'Daily downloads',
                'data' => $values,
                'borderColor' => 'rgba(25,92,227,0.55)',
                'backgroundColor' => 'rgba(25,92,227,0.08)',
                'borderWidth' => 1,
                'pointRadius' => $sparse ? 3 : 0,
                'fill' => true,
                'tension' => 0.3,
                'order' => 2,
            ],
        ];

        /*
         * Both derived lines need a series to be derived from. Under the
         * regression floor trend() already returns nulls, and a baseline
         * over four points is just the points again -- drawing either would
         * put two meaningless entries in the legend and imply we had
         * measured something.
         */
        if (count($values) >= RepoAnalytics::MIN_TREND_POINTS) {
            $trend = $analytics->trend($values);

            $datasets[] = [
                'type' => 'line',
                'label' => 'Baseline',
                'data' => $analytics->baseline($values),
                'borderColor' => '#9ca3af',
                'borderDash' => [6, 4],
                'borderWidth' => 2,
                'pointRadius' => 0,
                'fill' => false,
                'order' => 1,
            ];

            $datasets[] = [
                'type' => 'line',
                'label' => 'Trend ('.$trend['direction'].')',
                'data' => $trend['values'],
                'borderColor' => match ($trend['direction']) {
                    'rising' => '#16a34a',
                    'falling' => '#dc2626',
                    default => '#6b7280',
                },
                'borderWidth' => 2,
                'pointRadius' => 0,
                'fill' => false,
                'order' => 0,
            ];
        }

        return ['datasets' => $datasets, 'labels' => $labels];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'maintainAspectRatio' => false,
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                ],
                'x' => [
                    'ticks' => ['maxTicksLimit' => 12, 'maxRotation' => 0],
                    'grid' => ['display' => false],
                ],
            ],
            'plugins' => [
                'legend' => ['position' => 'bottom', 'labels' => ['boxWidth' => 12]],
            ],
        ];
    }
}
