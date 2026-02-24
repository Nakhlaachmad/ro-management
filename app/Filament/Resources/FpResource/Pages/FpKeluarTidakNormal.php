<?php

namespace App\Filament\Resources\FpResource\Pages;
use Filament\Forms;
use App\Filament\Resources\FpResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Builder;

class FpKeluarTidakNormal extends ListRecords
{
    protected static string $resource = FpResource::class;

    // protected static ?string $navigationGroup = 'Fps';
    // protected static ?string $navigationParentItem = 'Fps';
    protected static ?string $navigationLabel = 'FP Keluar Tidak Normal';
    protected static ?int $navigationSort = 4;
    protected static ?string $title = 'FP Keluar Tidak Normal';
    // ✅ Filter data
    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()
            ->whereNotNull('tanggal_keluar')
            ->whereRaw('tanggal_keluar < DATE_ADD(tanggal_masuk, INTERVAL 3 MONTH)');
    }

    // ✅ Kolom
    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('name')
                ->label('Nama FP')
                ->searchable(),

            Tables\Columns\TextColumn::make('team.name')
                ->label('Team'),

            Tables\Columns\TextColumn::make('status')
                ->badge()
                ->color(fn ($state) => $state === 'nonaktif' ? 'warning' : 'success'),


            // Tables\Columns\TextColumn::make('tanggal_masuk')
            //     ->date(),

            Tables\Columns\TextColumn::make('tanggal_keluar')
                ->date(),

            Tables\Columns\TextColumn::make('comment')
                ->wrap(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->recordUrl(null)
            ->defaultSort('created_at', 'desc')
            ->columns([
            Tables\Columns\TextColumn::make('name')
                ->label('Nama FP')
                ->searchable()
                ->action(
                    Tables\Actions\Action::make('view')
                        ->modalHeading(fn ($record) => 'Detail FP - ' . $record->name)
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Tutup')
                        ->fillForm(fn ($record) => [
                            'name' => $record->name,
                            'team' => $record->team?->name,
                            'no_tlfn' => $record->no_tlfn,
                            'grade' => $record->grade,
                            'jalur' => $record->jalur,
                            'status' => $record->status,
                            'tanggal_masuk' => $record->tanggal_masuk,
                            'tanggal_keluar' => $record->tanggal_keluar,
                            'comment' => $record->comment,
                        ])
                        ->form([
                            Forms\Components\TextInput::make('name')
                                ->label('Nama')
                                ->disabled(),

                            Forms\Components\TextInput::make('team')
                                ->label('Team')
                                ->disabled(),

                            Forms\Components\TextInput::make('no_tlfn')
                                ->label('No Telepon')
                                ->disabled(),

                            Forms\Components\TextInput::make('grade')
                                ->disabled(),

                            Forms\Components\TextInput::make('jalur')
                                ->disabled(),

                            Forms\Components\TextInput::make('status')
                                ->disabled(),

                            Forms\Components\DatePicker::make('tanggal_masuk')
                                ->disabled(),

                            Forms\Components\DatePicker::make('tanggal_keluar')
                                ->disabled(),

                            Forms\Components\Textarea::make('comment')
                                ->disabled(),
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

                Tables\Columns\TextColumn::make('grade')
                    ->sortable(),

                Tables\Columns\TextColumn::make('jalur')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'warning' => 'nonaktif',
                    ]),

                // Tables\Columns\TextColumn::make('tanggal_masuk')
                //     ->label('Tanggal Masuk')
                //     ->date(),

                Tables\Columns\TextColumn::make('tanggal_keluar')
                    ->label('Tanggal Keluar')
                    ->date(),

                Tables\Columns\TextColumn::make('comment')
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

                Tables\Filters\SelectFilter::make('team_id')
                    ->label('Team')
                    ->relationship('team', 'team'),
            ])
            ->actions([

                EditAction::make()
                    ->visible(fn ($record) =>
                        $record->status !== 'nonaktif'
                    ),

                DeleteAction::make()
                    ->visible(fn ($record) =>
                        $record->status !== 'nonaktif'
                    ),

                Action::make('comment')
                    ->label('Tambah Comment')
                    ->icon('heroicon-o-chat-bubble-left')
                    ->visible(fn ($record) =>
                        $record->status === 'nonaktif' &&
                        !is_null($record->tanggal_keluar) &&
                        empty($record->comment)
                    )
                    ->form([
                        Textarea::make('comment')
                            ->label('Comment')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function ($record, array $data) {
                        $record->comment = $data['comment'];
                        $record->save();
                    }),
            ])
            ->bulkActions([]);
    }


    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\FpKeluarTidakNormalStats::class,
        ];
    }
}
