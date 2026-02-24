<?php

namespace App\Filament\Resources;
use App\Filament\Resources\TeamResource\Pages;
use App\Filament\Resources\TeamResource\RelationManagers;
use App\Models\Team;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Auth;

class TeamResource extends Resource
{


    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationLabel = 'Teams';
    protected static ?string $navigationGroup = null;
    protected static ?int $navigationSort = 3;



    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->label('Nama Atasan')
                    ->required()
                    ->maxLength(255),

                TextInput::make('team')
                    ->label('Nama Team')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public static function getWidgets(): array
    {
        return [
            \App\Filament\Resources\TeamResource\Widgets\TeamStats::class,
        ];
    }



public static function table(Table $table): Table
{
    return $table
        ->query(
            Team::withCount([
                'fps as aktif_count' => function ($query) {
                    $query->where('status', 'aktif');
                }
            ])
        )
        ->columns([
            TextColumn::make('name')
                ->label('Nama MM')
                ->searchable(),
            TextColumn::make('team')
                ->label('Nama Team')
                ->searchable()
                ->sortable(),

            TextColumn::make('aktif_count')
                ->label('Total Anggota Aktif')
                ->badge()
                ->color('success')
                ->sortable(),
        ])
        ->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ]);
}



    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTeams::route('/'),
            'create' => Pages\CreateTeam::route('/create'),
            'edit' => Pages\EditTeam::route('/{record}/edit'),
        ];
    }



    public static function canViewAny(): bool
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        return $user?->hasRole('admin') ?? false;
    }

    public static function canCreate(): bool
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        return $user?->hasRole('admin') ?? false;
    }

    public static function canEdit($record): bool
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        return $user?->hasRole('admin') ?? false;
    }

    public static function canDelete($record): bool
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        return $user?->hasRole('admin') ?? false;
    }

}
