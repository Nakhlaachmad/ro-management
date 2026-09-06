<?php

namespace App\Filament\Widgets;

use App\Models\Fp;
use Filament\Tables;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class FpKeluarTidakNormalTable extends BaseWidget
{
    protected static ?string $heading = 'FP Keluar Tidak Normal';
    protected static ?int $sort = 2;
    protected static bool $isDiscovered = false;
    protected static string $view = 'filament.widgets.more-tidak-normal';
    protected function getTableQuery(): Builder
    {
        return Fp::query()
            ->with('team')
            ->keluarTidakNormal()
            ->latest()
            ->limit(5);
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('name')
                ->label('Nama'),

            Tables\Columns\TextColumn::make('team')
                ->label('Team')
                ->getStateUsing(fn ($record) =>
                    $record->team
                        ? $record->team->name . ' > ' . $record->team->team
                        : '-'
                ),

            Tables\Columns\TextColumn::make('tanggal_keluar')
                ->label('Tanggal Keluar')
                ->date(),
            
            Tables\Columns\TextColumn::make('comment')
                    ->label('Comment')
                    ->wrap(),
        ];
    }

    protected function isTablePaginationEnabled(): bool
    {
        return false;
    }
}
