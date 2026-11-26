<?php

declare(strict_types=1);

namespace App\Domain\FP;

enum RecruitmentSourceType: string
{
    case Hrd = 'HRD';
    case Team = 'TEAM';
    case Fp = 'FP';
}
