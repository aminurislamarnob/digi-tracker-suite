<?php

namespace App\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the table's current query as CSV.
 *
 * Deliberately not Filament's ExportAction. That one queues, batches and
 * notifies -- infrastructure this workload does not need at a few thousand
 * rows -- and more importantly it exports whatever columns the table has.
 * Here the columns are declared explicitly at each call site, so adding a
 * column to a table cannot silently add it to an export.
 *
 * That distinction is load-bearing for one table in particular: the plugin
 * inventory is aggregate-view only and must never leave in a file. Because
 * this takes an explicit map rather than reading the table, there is no
 * path by which it could.
 *
 * Streamed rather than built in memory: a generator keeps a hundred
 * thousand rows from becoming a hundred thousand rows of RAM.
 */
class ExportTableAction extends Action
{
    /** @var array<string, string|Closure> */
    protected array $columns = [];

    protected ?Closure $queryUsing = null;

    public static function getDefaultName(): ?string
    {
        return 'export';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Export CSV')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray');
    }

    /**
     * @param  array<string, string|Closure>  $columns  header => attribute or closure
     */
    public function columns(array $columns): static
    {
        $this->columns = $columns;

        return $this;
    }

    public function queryUsing(Closure $callback): static
    {
        $this->queryUsing = $callback;

        return $this;
    }

    public function getFilename(): string
    {
        // No clock in the name beyond the date: two exports on the same day
        // should overwrite rather than accumulate in somebody's downloads.
        return str($this->getLivewire()->getTable()->getHeading() ?? 'export')
            ->slug()
            ->append('-', now()->toDateString(), '.csv')
            ->toString();
    }

    public function handleExport(): StreamedResponse
    {
        $query = ($this->queryUsing)($this->getLivewire()->getFilteredSortedTableQuery());

        $columns = $this->columns;

        return response()->streamDownload(function () use ($query, $columns) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, array_keys($columns));

            $query->chunkById(500, function ($records) use ($handle, $columns) {
                foreach ($records as $record) {
                    fputcsv($handle, array_map(
                        fn ($column) => $column instanceof Closure ? $column($record) : data_get($record, $column),
                        array_values($columns),
                    ));
                }
            });

            fclose($handle);
        }, $this->getFilename(), ['Content-Type' => 'text/csv']);
    }

    /**
     * @param  array<string, string|Closure>  $columns
     */
    public static function for(array $columns, ?Closure $queryUsing = null): static
    {
        return static::make()
            ->columns($columns)
            ->queryUsing($queryUsing ?? fn (Builder $query) => $query)
            ->action(fn (self $action) => $action->handleExport());
    }
}
