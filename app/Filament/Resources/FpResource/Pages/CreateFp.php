<?php

namespace App\Filament\Resources\FpResource\Pages;

use App\Filament\Resources\FpResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateFp extends CreateRecord
{
    protected static string $resource = FpResource::class; // WAJIB ADA

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Auth::id();
        return $data;
    }
}
