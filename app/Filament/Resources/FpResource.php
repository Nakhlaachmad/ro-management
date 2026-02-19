<?php

namespace App\Filament\Resources;
use Illuminate\Support\Facades\Gate;
use Filament\Navigation\NavigationItem;
use App\Filament\Resources\FpResource\Pages;
use App\Models\Fp;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Actions\Action;
use Carbon\Carbon;

class FpResource extends Resource
{
    protected static ?string $model = Fp::class;

    protected static ?string $navigationGroup = 'Fps';
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?int $navigationSort = 1;

    // ===============================
    // 🔐 PERMISSION CONTROL
    // ===============================

    public static function canViewAny(): bool
    {
        
        return Gate::allows('fp.view');
    }

    public static function canCreate(): bool
    {
        return Gate::allows('fp.create');
    }

    public static function canEdit($record): bool
    {
        return Gate::allows('fp.edit') 
        && $record->status === 'aktif';
    }

    public static function canDelete($record): bool
    {
        return Gate::allows('fp.delete') 
        && $record->status === 'aktif';
    }

    // ===============================
    // 📋 FORM
    // ===============================

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->label('Nama')
                ->required(),

            Textarea::make('alamat')
                ->label('Alamat')
                ->required(),

            TextInput::make('no_tlfn')
                ->label('No Telepon')
                ->required(),

            TextInput::make('lulusan')
                ->required(),

            DatePicker::make('tanggal_masuk')
                ->required(),

            Select::make('team_id')
                ->label('Team')
                ->relationship('team', 'name')
                ->searchable()
                ->preload()
                ->required(),

            Select::make('status')
                ->options([
                    'aktif' => 'Aktif',
                    'nonaktif' => 'Non Aktif',
                ])
                ->default('aktif')
                ->required(),
        ]);
    }

    // ===============================
    // 📊 TABLE
    // ===============================

    public static function table(Table $table): Table
    {
        return $table
            ->recordUrl(null)
            ->columns([

                TextColumn::make('name')
                    ->searchable(),

                TextColumn::make('team.name')
                    ->label('Team')
                    ->sortable(),



                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(function ($record) {

                        if (!$record->tanggal_keluar) {
                            return 'FP Aktif';
                        }

                        $batas = Carbon::parse($record->tanggal_masuk)->addMonths(3);

                        if ($record->tanggal_keluar >= $batas) {
                            return 'FP Keluar Normal';
                        }

                        return 'FP Keluar Tidak Normal';
                    })
                    ->color(function ($state) {
                        return match ($state) {
                            'FP Aktif' => 'success',          // hijau
                            'FP Keluar Normal' => 'danger',   // merah
                            'FP Keluar Tidak Normal' => 'warning', // kuning
                            default => 'gray',
                        };
                    }),


                TextColumn::make('tanggal_masuk')
                    ->label('Tanggal Masuk')
                    ->date(),

                TextColumn::make('tanggal_keluar')
                    ->label('Tanggal Keluar')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->formatStateUsing(function ($state, $record) {
                        return $record->status === 'nonaktif'
                            ? $state   // tetap diproses oleh ->date()
                            : null;
                    }),

                TextColumn::make('comment')
                    ->label('Comment')
                    ->wrap(),
            ])

            ->actions([

                // ✏️ Edit
                Tables\Actions\EditAction::make(),

                // 🗑 Delete
                Tables\Actions\DeleteAction::make(),

                // 💬 Comment
                Action::make('comment')
                    ->label('Tambah Comment')
                    ->icon('heroicon-o-chat-bubble-left')
                        ->visible(fn ($record) =>
                            Gate::allows('fp.comment') &&
                            $record->status === 'nonaktif' &&
                            is_null($record->tanggal_keluar) &&
                            empty($record->comment)
                    )
                    ->form([
                        Textarea::make('comment')
                            ->label('Comment')
                            ->required(),
                    ])
                    ->action(function ($record, array $data) {
                        $record->update([
                            'comment' => $data['comment'],
                        ]);
                    }),
            ])
            ->actionsAlignment('start');
    }

    public static function getRelations(): array
    {
        return [];
    }

    // ===============================
    // 📌 NAVIGATION
    // ===============================

    public static function getNavigationItems(): array
    {
        return [
            ...parent::getNavigationItems(),

            NavigationItem::make('FP Aktif')
                ->group('Fps')
                ->icon('heroicon-o-check-circle')
                ->url(static::getUrl('aktif'))
                ->sort(1),

            NavigationItem::make('FP Keluar Normal')
                ->group('Fps')
                ->icon('heroicon-o-arrow-right-circle')
                ->url(static::getUrl('keluar-normal'))
                ->sort(2),

            NavigationItem::make('FP Keluar Tidak Normal')
                ->group('Fps')
                ->icon('heroicon-o-exclamation-circle')
                ->url(static::getUrl('keluar-tidak-normal'))
                ->sort(3),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFps::route('/'),
            'create' => Pages\CreateFp::route('/create'),
            'edit' => Pages\EditFp::route('/{record}/edit'),
            'aktif' => Pages\FpAktif::route('/aktif'),
            'keluar-normal' => Pages\FpKeluarNormal::route('/keluar-normal'),
            'keluar-tidak-normal' => Pages\FpKeluarTidakNormal::route('/keluar-tidak-normal'),
        ];
    }
}