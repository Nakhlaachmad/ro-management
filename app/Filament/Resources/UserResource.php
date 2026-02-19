<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Spatie\Permission\Models\Role;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Auth;

class UserResource extends Resource
{


    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationLabel = 'Users';
    protected static ?string $navigationGroup = null;
    protected static ?int $navigationSort = 2;


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true),

                TextInput::make('password')
                    ->revealable()
                    ->password()
                    ->required(fn ($record) => $record === null)
                    ->dehydrated(fn ($state) => filled($state))
                    ->maxLength(255),

                Select::make('roles')
                    ->label('Role')
                    ->relationship('roles', 'name')
                    ->preload()
                    ->searchable()
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('roles.name')
                    ->label('Role')
                    ->badge(),
                TextColumn::make('created_at')->dateTime(),
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
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
