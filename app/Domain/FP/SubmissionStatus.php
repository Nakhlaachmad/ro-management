<?php

declare(strict_types=1);

namespace App\Domain\FP;

enum SubmissionStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
}
