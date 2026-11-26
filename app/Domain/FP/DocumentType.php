<?php

declare(strict_types=1);

namespace App\Domain\FP;

enum DocumentType: string
{
    case ProfilePhoto = 'PROFILE_PHOTO';
    case Ktp = 'KTP';
    case Ijazah = 'IJAZAH';
}
