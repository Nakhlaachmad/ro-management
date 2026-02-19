<?php

namespace App\Filament\Resources\FpResource\Pages;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Filament\Widgets\FpAktifStatsWidget;
    use Filament\Tables;
use Filament\Tables\Table;

use App\Filament\Resources\FpResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class FpAktif extends ListRecords
{
    protected static string $resource = FpResource::class;
    protected static ?string $navigationGroup = 'Fps';
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
        ->columns([
            Tables\Columns\TextColumn::make('name')
                ->searchable(),

            Tables\Columns\TextColumn::make('team.name')
                ->label('Team'),

            Tables\Columns\BadgeColumn::make('status')
                ->colors([
                    'success' => 'aktif',
                ]),

            Tables\Columns\TextColumn::make('tanggal_masuk')
                ->date(),
        ])
        ->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ]);
}

    




}
