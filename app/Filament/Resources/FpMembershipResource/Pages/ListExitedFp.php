<?php

declare(strict_types=1);

namespace App\Filament\Resources\FpMembershipResource\Pages;

final class ListExitedFp extends ListFpMemberships
{
    protected string $directoryScope = 'exited';

    protected static ?string $title = 'FP Keluar';
}
