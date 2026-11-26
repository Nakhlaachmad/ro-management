<?php

declare(strict_types=1);

namespace App\Filament\Resources\FpSubmissionResource\Pages;

final class ListFpSubmissions extends \Filament\Resources\Pages\ListRecords
{
    use \App\Filament\FP\GuardsVictoryActions;

    protected static string $resource = \App\Filament\Resources\FpSubmissionResource::class;
}
