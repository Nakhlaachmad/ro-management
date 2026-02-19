<?php

namespace App\Filament\Resources\FpResource\Pages;

use App\Filament\Resources\FpResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFps extends ListRecords
{
    protected static string $resource = FpResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
