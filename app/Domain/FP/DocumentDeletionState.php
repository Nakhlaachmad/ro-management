<?php

declare(strict_types=1);

namespace App\Domain\FP;

enum DocumentDeletionState: string
{
    case None = 'NONE';
    case Requested = 'REQUESTED';
    case Deleted = 'DELETED';
    case Failed = 'FAILED';
}
