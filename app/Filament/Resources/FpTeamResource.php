<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Application\FP\FeatureAccess;
use App\Application\FP\FpEngine;
use App\Models\FpTeam;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class FpTeamResource extends Resource
{
    protected static ?string $model = FpTeam::class;

    protected static ?string $navigationLabel = 'Team FP';

    protected static ?string $navigationGroup = 'Financial Planner';

    protected static ?string $slug = 'fp-team-profiles';

    public static function canViewAny(): bool
    {
        return FeatureAccess::allowed(auth()->user(), 'f2.teams.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('mm.full_name')->label('Nama MM')->visible(fn () => config('fp.organization_enabled'))->placeholder('MM historis belum direkonsiliasi'),
            Tables\Columns\TextColumn::make('created_at')->label('Dibuat')->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('updated_at')->label('Diperbarui')->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),Tables\Columns\TextColumn::make('display_name')->label('Team')->searchable()->sortable(), Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean()->sortable(), Tables\Columns\TextColumn::make('leader.full_name')->label('Ketua Team')->sortable()])->actions([
            Tables\Actions\Action::make('dissolveTeam')->label('Bubarkan Team')->icon('heroicon-o-archive-box')->color('danger')
                ->visible(fn ($record) => config('fp.organization_enabled') && $record->is_active && FeatureAccess::allowed(auth()->user(), 'f2.teams'))
                ->authorize(fn () => FeatureAccess::allowed(auth()->user(), 'f2.teams'))
                ->modalWidth('5xl')->form(\App\Filament\FP\TeamOrganizationForm::dissolutionFields())
                ->fillForm(fn ($record) => ['new_teams' => [], 'moves' => \App\Models\FpMembership::where('team_id', $record->team_id)->where('lifecycle_state', 'ACTIVE')->orderBy('id')->get(['id'])->map(fn ($fp) => ['membership_id' => $fp->id, 'selected' => false, 'destination' => null])->all()])
                ->action(function ($record, array $data): void {
                    abort_unless($data['confirmed'] ?? false, 422);
                    app(\App\Application\FP\DissolveTeam::class)->execute(auth()->user(), $record->team_id, ['moves' => \App\Filament\FP\TeamOrganizationForm::moves($data), 'new_teams' => array_values($data['new_teams'] ?? [])]);
                    \Filament\Notifications\Notification::make()->success()->title('Team dibubarkan; seluruh perpindahan tercatat')->send();
                }),

            Tables\Actions\Action::make('profile')->label('Edit profil')->tooltip('Edit profil Team')->requiresConfirmation()->visible(fn () => FeatureAccess::allowed(auth()->user(), 'f2.teams'))->authorize(fn () => FeatureAccess::allowed(auth()->user(), 'f2.teams'))->form([\Filament\Forms\Components\TextInput::make('display_name')->label('Nama Team')->required(), \Filament\Forms\Components\Toggle::make('is_active')->label('Aktif')->disabled(fn () => config('fp.organization_enabled'))->dehydrated(true)])->fillForm(fn ($record) => $record->getAttributes())->action(function ($record, array $data, \Filament\Forms\Form $form) {
                try {
                    app(FpEngine::class)->saveTeamProfile(auth()->user(), $record->team_id, $data['display_name'], $data['is_active']);
                } catch (\Illuminate\Validation\ValidationException $exception) {
                    $errors = [];
                    foreach ($exception->errors() as $field => $messages) {
                        $errors[$form->getStatePath().'.'.($field === 'is_active' ? 'is_active' : 'display_name')] = $messages;
                    }
                    throw \Illuminate\Validation\ValidationException::withMessages($errors);
                }
            }),
            Tables\Actions\Action::make('leader')->label('Ketua Team')->tooltip('Pilih ketua Team')->visible(fn () => FeatureAccess::allowed(auth()->user(), 'f2.teams'))->authorize(fn () => FeatureAccess::allowed(auth()->user(), 'f2.teams'))->form(fn ($record) => [\Filament\Forms\Components\Select::make('leader_fp_id')->label('Ketua Team — FP Aktif')->helperText(fn () => config('fp.organization_enabled') ? 'FP ACTIVE dapat memimpin lintas Team tanpa memindahkan keanggotaan. Tidak boleh merangkap ketua Team aktif.' : 'Pilihan lintas tim menunggu aktivasi constraint dan riwayat jabatan.')->options(fn () => config('fp.organization_enabled') ? \App\Filament\FP\TeamOrganizationForm::leaderOptions($record->team_id) : \App\Models\FpMembership::where('team_id', $record->team_id)->where('lifecycle_state', 'ACTIVE')->limit(200)->pluck('full_name', 'id'))->searchable()->nullable()])->action(fn ($record, array $data) => app(FpEngine::class)->assignTeamLeader(auth()->user(), $record->team_id, isset($data['leader_fp_id']) ? (int) $data['leader_fp_id'] : null)),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => FpTeamResource\Pages\ListFpTeams::route('/')];
    }
}
