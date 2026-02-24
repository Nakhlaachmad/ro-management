<?php

namespace App\Filament\Resources;
use Filament\Forms;
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
use Filament\Tables\Actions\Action;
use Carbon\Carbon;

class FpResource extends Resource
{
    protected static ?string $model = Fp::class;
    protected static ?string $pluralModelLabel = 'FP';
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

            TextInput::make('no_tlfn')
                ->label('No Telepon')
                ->required(),

            TextInput::make('no_rekening')
                ->label('No Rekening')
                ->required(),

            TextInput::make('no_ktp')
                ->label('No KTP')
                ->required(),

            Textarea::make('alamat_ktp')
                ->label('Alamat KTP')
                ->required(),

            Textarea::make('alamat')
                ->label('Alamat Tinggal')
                ->required(),

            TextInput::make('tempat_lahir')
                ->label('Tempat Lahir')
                ->required(),

            DatePicker::make('tanggal_lahir')
                ->label('Tanggal Lahir')
                ->required(),

            TextInput::make('lulusan')
                ->required(),

            Select::make('grade')
                ->options([
                    'A' => 'A',
                    'B' => 'B',
                ])
                ->required(),

            Select::make('jalur')
                ->options([
                    'RO' => 'RO',
                    'MGM' => 'MGM',
                ])
                ->required(),

            DatePicker::make('tanggal_masuk')
                ->default(now())
                ->required(),

            Select::make('team_id')
                ->label('Team')
                ->relationship(
                    name: 'team',
                    titleAttribute: 'team'
                )
                ->getOptionLabelFromRecordUsing(function ($record) {
                    return $record->name . ' > ' . $record->team;
                })
                ->searchable()
                ->preload()
                ->required(),
  
            Select::make('status')
                ->options([
                    'aktif' => 'Aktif',
                    'nonaktif' => 'Non Aktif',
                ])
                ->default('aktif')
                ->required()
                ->reactive()
                ->afterStateUpdated(function ($state, callable $set) {
                    if ($state === 'nonaktif') {
                        $set('tanggal_keluar', now());
                    } else {
                        $set('tanggal_keluar', null);
                    }
                }),


        ]);
    }

    // ===============================
    // 📊 TABLE
    // ===============================

    public static function table(Table $table): Table
    {
        return $table
            ->recordUrl(null)
            ->defaultSort('created_at', 'desc')
            ->columns([

            TextColumn::make('name')
                ->label('Nama FP')
                ->searchable()
                ->action(
                    Tables\Actions\Action::make('view')
                        ->modalHeading(fn ($record) => 'Detail FP - ' . $record->name)
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Tutup')
                        ->fillForm(fn ($record) => [
                            'name' => $record->name,
                            'no_tlfn' => $record->no_tlfn,
                            'no_rekening' => $record->no_rekening,
                            'no_ktp' => $record->no_ktp,
                            'alamat_ktp' => $record->alamat_ktp,
                            'alamat' => $record->alamat,
                            'tempat_lahir' => $record->tempat_lahir,
                            'tanggal_lahir' => $record->tanggal_lahir,
                            'lulusan' => $record->lulusan,
                            'grade' => $record->grade,
                            'jalur' => $record->jalur,
                            'tanggal_masuk' => $record->tanggal_masuk,
                            'tanggal_keluar' => $record->tanggal_keluar,
                            'comment' => $record->comment,
                        ])
                        ->form([
                            Forms\Components\TextInput::make('name')->disabled(),
                            Forms\Components\TextInput::make('no_tlfn')->disabled(),
                            Forms\Components\TextInput::make('no_rekening')->disabled(),
                            Forms\Components\TextInput::make('no_ktp')->disabled(),
                            Forms\Components\Textarea::make('alamat_ktp')->disabled(),
                            Forms\Components\Textarea::make('alamat')->disabled(),
                            Forms\Components\TextInput::make('tempat_lahir')->disabled(),
                            Forms\Components\DatePicker::make('tanggal_lahir')->disabled(),
                            Forms\Components\TextInput::make('lulusan')->disabled(),
                            Forms\Components\TextInput::make('grade')->disabled(),
                            Forms\Components\TextInput::make('jalur')->disabled(),
                            Forms\Components\DatePicker::make('tanggal_masuk')->disabled(),
                            Forms\Components\DatePicker::make('tanggal_keluar')->disabled(),
                            Forms\Components\Textarea::make('comment')->disabled(),
                        ])
                ),



                Tables\Columns\TextColumn::make('team')
                    ->label('Team')
                    ->getStateUsing(function ($record) {
                        return $record->team
                            ? $record->team->name . ' > ' . $record->team->team
                            : '-';
                    })
                    ->sortable(query: function ($query, $direction) {
                        $query->orderBy('team_id', $direction);
                    }),


                TextColumn::make('grade')
                    ->sortable(),

                TextColumn::make('jalur')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->sortable()
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
                            'FP Aktif' => 'success',
                            'FP Keluar Normal' => 'danger',
                            'FP Keluar Tidak Normal' => 'warning',
                            default => 'gray',
                        };
                    }),

                // TextColumn::make('tanggal_masuk')
                //     ->label('Tanggal Masuk')
                //     ->date(),

                // TextColumn::make('tanggal_keluar')
                //     ->label('Tanggal Keluar')
                //     ->date('d M Y')
                //     ->placeholder('—')
                //     ->formatStateUsing(function ($state, $record) {
                //         return $record->status === 'nonaktif'
                //             ? $state
                //             : null;
                //     }),

                TextColumn::make('comment')
                    ->label('Comment')
                    ->wrap(),
            ])

            ->filters([
                Tables\Filters\SelectFilter::make('grade')
                    ->options([
                        'A' => 'A',
                        'B' => 'B',
                    ]),

                Tables\Filters\SelectFilter::make('jalur')
                    ->options([
                        'RO' => 'RO',
                        'MGM' => 'MGM',
                    ]),

                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'aktif' => 'Aktif',
                        'nonaktif' => 'Non Aktif',
                    ]),

                Tables\Filters\SelectFilter::make('team_id')
                    ->label('Team')
                    ->relationship('team', 'team'),
            ])

            ->actions([

                Tables\Actions\EditAction::make(),

                Tables\Actions\DeleteAction::make(),

                Action::make('comment')
                    ->label('Tambah Comment')
                    ->icon('heroicon-o-chat-bubble-left')
                    ->visible(fn ($record) =>
                        Gate::allows('fp.comment') &&
                        $record->status === 'nonaktif' &&
                        !is_null($record->tanggal_keluar) &&
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

        NavigationItem::make('FP')
            ->group('FP')
            ->icon('heroicon-o-rectangle-stack')
            ->url(static::getUrl())
            ->isActiveWhen(fn () => request()->routeIs('filament.admin.resources.fps.index')),

        NavigationItem::make('FP Aktif')
            ->group('FP')
            ->icon('heroicon-o-check-circle')
            ->url(static::getUrl('aktif'))
            ->isActiveWhen(fn () => request()->routeIs('filament.admin.resources.fps.aktif')),

        NavigationItem::make('FP Keluar Normal')
            ->group('FP')
            ->icon('heroicon-o-arrow-right-circle')
            ->url(static::getUrl('keluar-normal'))
            ->isActiveWhen(fn () => request()->routeIs('filament.admin.resources.fps.keluar-normal')),

        NavigationItem::make('FP Keluar Tidak Normal')
            ->group('FP')
            ->icon('heroicon-o-exclamation-circle')
            ->url(static::getUrl('keluar-tidak-normal'))
            ->isActiveWhen(fn () => request()->routeIs('filament.admin.resources.fps.keluar-tidak-normal')),
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
