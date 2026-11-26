<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Application\FP\FeatureAccess;
use App\Application\FP\RecruitmentPaths;
use App\Models\FpRecruitmentPath;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class FpRecruitmentPathResource extends Resource
{
    protected static ?string $model = FpRecruitmentPath::class;

    protected static bool $isDiscovered = false;

    protected static ?string $modelLabel = 'Sumber Rekrutmen';

    protected static ?string $pluralModelLabel = 'Master Sumber Rekrutmen';

    protected static ?string $navigationLabel = 'Master Sumber Rekrutmen';

    protected static ?string $slug = 'fp-recruitment-paths';

    public static function canViewAny(): bool
    {
        return ! config('fp.recruitment_team_enabled') && FeatureAccess::allowed(auth()->user(), 'f3.jalur.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function fields(bool $create): array
    {
        return [

            \Filament\Forms\Components\TextInput::make('name')->label('Sumber Rekrutmen')->required()->maxLength(120),
            \Filament\Forms\Components\Textarea::make('description')->label('Deskripsi')->maxLength(2000),
            \Filament\Forms\Components\Toggle::make('is_active')->label('Aktif untuk approval baru')->default(true)->required(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('created_at')->label('Dibuat')->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('updated_at')->label('Diperbarui')->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),

            Tables\Columns\TextColumn::make('name')->label('Sumber Rekrutmen')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('description')->label('Deskripsi')->wrap()->limit(100),
            Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean()->sortable(),
        ])->filters([Tables\Filters\TernaryFilter::make('is_active')->label('Aktif')])->actions([
            Tables\Actions\Action::make('editPath')->label('Edit Sumber Rekrutmen')->tooltip('Edit nama dan status sumber')->form(self::fields(false))->fillForm(fn ($record) => $record->getAttributes())->requiresConfirmation()->modalDescription('Perubahan master tidak mengubah snapshot membership terdahulu.')->visible(fn () => FeatureAccess::allowed(auth()->user(), 'f3.jalur.manage'))->action(fn ($record, array $data) => app(RecruitmentPaths::class)->save(auth()->user(), $record->id, $data)),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => FpRecruitmentPathResource\Pages\ListRecruitmentPaths::route('/')];
    }
}
