<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
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
                    ->minLength(8)
                    ->rule('regex:/[a-z]/')
                    ->rule('regex:/[A-Z]/')
                    ->rule('regex:/[0-9]/')
                    ->rule('regex:/[^A-Za-z0-9]/')
                    ->helperText('Minimal 8 karakter, berisi huruf besar, huruf kecil, angka, dan simbol.')
                    ->maxLength(255),

                Select::make('role_display')
                    ->label('Role')
                    ->options(['ro' => 'RO'])
                    ->default('ro')
                    ->formatStateUsing(fn (): string => 'ro')
                    ->disabled()
                    ->dehydrated(false),
            ]);
    }

    /** @return Builder<\App\Models\User> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('roles', fn (Builder $query) => $query->where('name', 'ro'));
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

        return ($user?->hasRole('admin') ?? false)
            && $record instanceof \App\Models\User
            && $record->hasRole('ro');
    }

    public static function canDelete($record): bool
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        return $user?->hasRole('admin') ?? false;
    }
}
