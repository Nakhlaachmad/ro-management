<?php

declare(strict_types=1);

namespace App\Filament\Resources\FpMembershipResource\Pages;

final class ListAbnormalExitedFp extends ListFpMemberships
{
    protected string $directoryScope = 'abnormal';

    protected static ?string $title = 'FP Keluar Tidak Normal';
}
