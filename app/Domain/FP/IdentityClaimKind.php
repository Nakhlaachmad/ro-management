<?php

declare(strict_types=1);

namespace App\Domain\FP;

enum IdentityClaimKind: string
{
    case Pending = 'PENDING';
    case Active = 'ACTIVE';
}
