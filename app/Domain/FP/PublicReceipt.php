<?php

declare(strict_types=1);

namespace App\Domain\FP;

final class PublicReceipt
{
    public static function make(string $uuid): array
    {
        return ['receipt' => $uuid, 'message' => 'Pendaftaran telah diterima untuk pemeriksaan HRD.'];
    }
}
