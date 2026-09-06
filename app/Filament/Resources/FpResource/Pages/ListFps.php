<?php

namespace App\Filament\Resources\FpResource\Pages;
use App\Filament\Resources\FpResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;
class ListFps extends ListRecords
{
    protected static string $resource = FpResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
    protected function authorizeAccess(): void
    {
        abort_unless(Gate::allows('fp.view'), 403);
    }
}
