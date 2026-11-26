<?php

declare(strict_types=1);

namespace App\Filament\Resources\FpMembershipResource\Pages;

final class ListNormalExitedFp extends ListFpMemberships
{
    protected string $directoryScope = 'normal';

    protected static ?string $title = 'FP Keluar Normal';
}
