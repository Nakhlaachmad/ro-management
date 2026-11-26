<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Application\FP\FeatureAccess;
use App\Application\FP\VictoryAccess;
use App\Application\FP\VictoryUsers;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class VictoryUserResource extends Resource
{
    protected static ?string $model = \App\Models\VictoryUser::class;

    protected static bool $isDiscovered = false;

    protected static ?string $slug = 'victory-users';

    protected static ?string $navigationLabel = 'User';

    public static function canViewAny(): bool
    {
        return VictoryUsers::readable(auth()->user());
    }

    public static function canView($record): bool
    {
        if (! $record instanceof \App\Models\User) {
            return false;
        }

        return self::canViewAny() && VictoryUsers::managed($record);
    }

    public static function canCreate(): bool
    {
        return VictoryUsers::enabled() && VictoryAccess::allowed(auth()->user(), 'f3.users.create');
    }

    public static function canEdit($record): bool
    {
        return self::canView($record) && VictoryAccess::allowed(auth()->user(), 'f3.users.edit');
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->select(['users.id', 'name', 'email', 'is_active', 'fp_app_managed', 'sso_subject', 'created_at', 'updated_at'])->where('fp_app_managed', true)->whereNull('sso_subject')->whereHas('roles', fn ($q) => $q->where('guard_name', 'web')->whereIn('name', ['admin', 'hrd', 'ro', 'bm']))->whereDoesntHave('roles', fn ($q) => $q->whereNotIn('name', ['admin', 'hrd', 'ro', 'bm'])->orWhere('guard_name', '<>', 'web'))->with('roles');
    }

    public static function fields(): array
    {
        return [\Filament\Forms\Components\TextInput::make('name')->label('Nama')->required()->maxLength(255), \Filament\Forms\Components\TextInput::make('email')->email()->required()->maxLength(255)->extraInputAttributes(['x-on:input' => '$event.target.value = $event.target.value.toLowerCase()'])->dehydrateStateUsing(fn ($state) => \Illuminate\Support\Str::lower($state))];
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('created_at')->label('Dibuat')->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('updated_at')->label('Diperbarui')->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),Tables\Columns\TextColumn::make('name')->searchable()->sortable(), Tables\Columns\TextColumn::make('email')->searchable()->sortable(), Tables\Columns\TextColumn::make('roles.name')->label('Role')->badge(), Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean()->sortable()])->filters([Tables\Filters\TernaryFilter::make('is_active')])->actions([
            Tables\Actions\ViewAction::make()->tooltip('Lihat User'),
            Tables\Actions\Action::make('editLocal')->label('Edit')->tooltip('Edit akun yang dikelola aplikasi')->form(self::fields())->fillForm(fn ($record) => ['name' => $record->name, 'email' => $record->email])->requiresConfirmation()->visible(fn ($record) => self::canEdit($record))->authorize(fn ($record) => self::canEdit($record))->action(fn ($record, array $data) => app(VictoryUsers::class)->command(auth()->user(), 'EDIT', $record->id, $data)),
            Tables\Actions\Action::make('permissionsLocal')->label('Permission operasional')->form([\Filament\Forms\Components\CheckboxList::make('permissions')->options(array_combine(FeatureAccess::PERMISSIONS, FeatureAccess::PERMISSIONS))])->fillForm(fn ($record) => ['permissions' => $record->permissions->where('guard_name', 'web')->pluck('name')->intersect(FeatureAccess::PERMISSIONS)->values()->all()])->requiresConfirmation()->visible(fn ($record) => self::canView($record) && VictoryAccess::allowed(auth()->user(), 'f3.users.permissions'))->authorize(fn ($record) => self::canView($record) && VictoryAccess::allowed(auth()->user(), 'f3.users.permissions'))->action(fn ($record, array $data) => app(VictoryUsers::class)->command(auth()->user(), 'PERMISSIONS', $record->id, $data)),
        ]);
    }

    public static function infolist(\Filament\Infolists\Infolist $list): \Filament\Infolists\Infolist
    {
        return $list->schema([\Filament\Infolists\Components\TextEntry::make('name'), \Filament\Infolists\Components\TextEntry::make('email'), \Filament\Infolists\Components\TextEntry::make('roles.name')->label('Role'), \Filament\Infolists\Components\TextEntry::make('is_active')->label('Aktif')]);
    }

    public static function getPages(): array
    {
        return ['index' => VictoryUserResource\Pages\ListVictoryUsers::route('/'), 'view' => VictoryUserResource\Pages\ViewVictoryUser::route('/{record}')];
    }
}
