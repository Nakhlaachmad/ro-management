<?php

namespace App\Filament\Resources\FpResource\Pages;

use App\Filament\Resources\FpResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Builder;

class FpKeluarNormal extends ListRecords
{
    protected static string $resource = FpResource::class;

    protected static ?string $navigationGroup = 'Fps';
    // protected static ?string $navigationParentItem = 'Fps';
    protected static ?string $navigationLabel = 'FP Keluar Normal';
    protected static ?int $navigationSort = 3;
    protected static ?string $title = 'FP Keluar Normal';
    // ✅ Filter data keluar normal
    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()
            ->whereNotNull('tanggal_keluar')
            ->whereRaw('tanggal_keluar >= DATE_ADD(tanggal_masuk, INTERVAL 3 MONTH)');
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
                ->color(fn ($state) => $state === 'nonaktif' ? 'danger' : 'success'),

            Tables\Columns\TextColumn::make('tanggal_masuk')
                ->date(),

            Tables\Columns\TextColumn::make('tanggal_keluar')
                ->date(),

            Tables\Columns\TextColumn::make('comment')
                ->label('Comment')
                ->wrap(),
        ];
    }

public function table(Table $table): Table
{
    return parent::table($table)

        // ✅ PAKSA pakai kolom dari page ini
        ->columns([
            Tables\Columns\TextColumn::make('name')
                ->label('Nama FP')
                ->searchable(),

            Tables\Columns\TextColumn::make('team.name')
                ->label('Team'),

Tables\Columns\TextColumn::make('status')
    ->badge()
    ->color(fn ($state) => $state === 'nonaktif' ? 'danger' : 'success'),


            Tables\Columns\TextColumn::make('tanggal_masuk')
                ->label('Tanggal Masuk')
                ->date(),

            Tables\Columns\TextColumn::make('tanggal_keluar')
                ->label('Tanggal Keluar')
                ->date(),

            Tables\Columns\TextColumn::make('comment')
                ->label('Comment')
                ->wrap(),
        ])
        
        ->actions([

            // ✏️ Edit hanya jika tidak nonaktif
            EditAction::make()
                ->visible(fn ($record) =>
                    $record->status !== 'nonaktif'
                ),

            // 🗑 Delete hanya jika tidak nonaktif
            DeleteAction::make()
                ->visible(fn ($record) =>
                    $record->status !== 'nonaktif'
                ),
            
            // 💬 Comment
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
            \App\Filament\Widgets\FpKeluarNormalStats::class,
        ];
    }
}
