<?php

namespace App\Filament\Resources\FpResource\Pages;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Filament\Widgets\FpAktifStatsWidget;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms;
use Filament\Tables\Actions\Action;


use App\Filament\Resources\FpResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class FpAktif extends ListRecords
{
    protected static string $resource = FpResource::class;
    // protected static ?string $navigationGroup = 'Fps';
    protected static ?string $navigationLabel = 'FP Aktif';
    protected static ?int $navigationSort = 2;
    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()
            ->where('status', 'aktif');
    }

    protected static ?string $title = 'FP Aktif';
    protected function getHeaderWidgets(): array
    {
        return [
            FpAktifStatsWidget::class,
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
                        'success' => 'aktif',
                    ]),

                Tables\Columns\TextColumn::make('tanggal_masuk')
                    ->date()
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
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
