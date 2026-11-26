<?php

declare(strict_types=1);

namespace App\Filament\Resources\FpMembershipResource\Pages;

use App\Application\FP\FpDirectory;
use Filament\Tables\Table;

class ListFpMemberships extends \Filament\Resources\Pages\ListRecords
{
    use \App\Filament\FP\GuardsVictoryActions;

    protected static string $resource = \App\Filament\Resources\FpMembershipResource::class;

    protected string $directoryScope = 'all';

    protected static ?string $title = 'Bank FP';

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\Action::make('registerFp')->label('Tambah FP')->url(fn () => static::getResource()::getUrl('create'))->visible(fn () => static::getResource()::canCreate()),
            \Filament\Actions\Action::make('exportDirectory')->label('Ekspor FP')->icon('heroicon-o-arrow-down-tray')
                ->visible(fn () => \App\Application\FP\FeatureAccess::allowed(auth()->user(), 'f3.reports.export'))
                ->form([
                    \Filament\Forms\Components\Select::make('format')->options(['csv' => 'CSV', 'xlsx' => 'XLSX'])->default('csv')->required(),
                    \Filament\Forms\Components\CheckboxList::make('columns')->label('Kolom ekspor')->options(\App\Application\FP\ReportColumns::AVAILABLE)->default(\App\Application\FP\ReportColumns::DEFAULTS)->required()->columns(2),
                ])->action(fn (array $data) => app(\App\Application\FP\DirectoryExport::class)->download(auth()->user(), $this->getFilteredSortedTableQuery(), $data['format'], $data['columns'])),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)->query(fn () => FpDirectory::scope(static::getResource()::getEloquentQuery(), $this->directoryScope));
    }
}
