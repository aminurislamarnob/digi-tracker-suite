<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),

            DeleteAction::make()
                /*
                 * Every foreign key to a project cascades. Deleting one takes
                 * its sites, reports, end users, deactivations and raw
                 * payloads with it, and no re-run can rebuild them: the
                 * heartbeats that produced them were fire-and-forget.
                 *
                 * Switching `is_active` off is almost always what was meant.
                 */
                ->modalDescription(fn () => 'This permanently deletes every site, report, end user and '
                    .'deactivation recorded for this project. Telemetry cannot be recovered. '
                    .'To stop accepting new data instead, switch the project inactive.')
                ->modalSubmitActionLabel('Delete the project and all its telemetry'),
        ];
    }
}
