<?php

declare(strict_types=1);

namespace App\Filament\Resources\FpMembershipResource\Pages;

final class ListActiveFp extends ListFpMemberships
{
    protected string $directoryScope = 'active';

    protected static ?string $title = 'FP Aktif';
}
