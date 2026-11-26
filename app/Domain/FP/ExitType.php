<?php

declare(strict_types=1);

namespace App\Domain\FP;

enum ExitType: string
{
    case Normal = 'NORMAL';
    case Abnormal = 'ABNORMAL';
}
