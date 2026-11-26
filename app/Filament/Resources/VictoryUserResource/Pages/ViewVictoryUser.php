<?php

declare(strict_types=1);

namespace App\Filament\Resources\VictoryUserResource\Pages;

final class ViewVictoryUser extends \Filament\Resources\Pages\ViewRecord
{
    use \App\Filament\FP\GuardsVictoryActions;

    protected static string $resource = \App\Filament\Resources\VictoryUserResource::class;
}
