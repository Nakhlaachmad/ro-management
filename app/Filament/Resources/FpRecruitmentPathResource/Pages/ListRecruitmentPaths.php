<?php

declare(strict_types=1);

namespace App\Filament\Resources\FpRecruitmentPathResource\Pages;

use App\Application\FP\FeatureAccess;
use App\Application\FP\RecruitmentPaths;
use App\Filament\Resources\FpRecruitmentPathResource;
use Filament\Resources\Pages\ListRecords;

final class ListRecruitmentPaths extends ListRecords
{
    use \App\Filament\FP\GuardsVictoryActions;

    protected static string $resource = FpRecruitmentPathResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\Action::make('createPath')->label('Tambah Sumber Rekrutmen')->form(FpRecruitmentPathResource::fields(true))->visible(fn () => FeatureAccess::allowed(auth()->user(), 'f3.jalur.manage'))->action(fn (array $data) => app(RecruitmentPaths::class)->save(auth()->user(), null, $data))];
    }
}
