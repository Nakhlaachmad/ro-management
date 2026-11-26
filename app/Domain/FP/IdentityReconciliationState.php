<?php

declare(strict_types=1);

namespace App\Domain\FP;

enum IdentityReconciliationState: string
{
    case Resolved = 'RESOLVED';
    case ReviewRequired = 'REVIEW_REQUIRED';
}
