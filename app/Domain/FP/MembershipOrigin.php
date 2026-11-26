<?php

declare(strict_types=1);

namespace App\Domain\FP;

enum MembershipOrigin: string
{
    case NewWorkflow = 'NEW';
    case Legacy = 'LEGACY';
}
