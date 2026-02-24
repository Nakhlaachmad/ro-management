<?php

namespace App\Filament\Resources\FpResource\Pages;

use App\Filament\Resources\FpResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFp extends EditRecord
{
    protected static string $resource = FpResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

}
