<?php

declare(strict_types=1);

namespace App\Filament\Resources\FpTeamResource\Pages;

use Filament\Resources\Pages\ListRecords;

final class ListFpTeams extends ListRecords
{
    use \App\Filament\FP\GuardsVictoryActions;

    protected static string $resource = \App\Filament\Resources\FpTeamResource::class;

    protected function getHeaderActions(): array
    {
        if (config('fp.organization_enabled')) {
            return [\Filament\Actions\Action::make('newTeam')->label('Buat Team')->visible(fn () => \App\Application\FP\FeatureAccess::allowed(auth()->user(), 'f2.teams'))
                ->authorize(fn () => \App\Application\FP\FeatureAccess::allowed(auth()->user(), 'f2.teams'))
                ->form(\App\Filament\FP\TeamOrganizationForm::createFields())
                ->action(function (array $data): void {
                    app(\App\Application\FP\FpEngine::class)->createTeam(auth()->user(), (string) $data['mm_fp_id'], $data['display_name']);
                    \Filament\Notifications\Notification::make()->success()->title('Team ACTIVE berhasil dibuat')->send();
                })];
        }
        return [\Filament\Actions\Action::make('newTeam')->label('Buat Team')->visible(fn () => \App\Application\FP\FeatureAccess::allowed(auth()->user(), 'f2.teams'))->form([\Filament\Forms\Components\TextInput::make('manager_name')->label('Nama MM')->required(), \Filament\Forms\Components\TextInput::make('display_name')->label('Nama Team')->required()])->action(fn (array $data) => app(\App\Application\FP\FpEngine::class)->createTeam(auth()->user(), $data['manager_name'], $data['display_name']))];
    }
}
