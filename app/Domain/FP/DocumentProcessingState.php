<?php

declare(strict_types=1);

namespace App\Domain\FP;

enum DocumentProcessingState: string
{
    case Processing = 'PROCESSING';
    case Ready = 'READY';
    case Failed = 'FAILED';
}
