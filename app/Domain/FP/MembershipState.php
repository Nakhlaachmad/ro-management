<?php

declare(strict_types=1);

namespace App\Domain\FP;

enum MembershipState: string
{
    case Active = 'ACTIVE';
    case Exited = 'EXITED';
}
