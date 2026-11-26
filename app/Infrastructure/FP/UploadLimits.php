<?php

declare(strict_types=1);

namespace App\Infrastructure\FP;

final class UploadLimits
{
    public static function calculate(string $upload, string $post): array
    {
        $application = 8 * 1024 * 1024;
        $uploadBytes = ini_parse_quantity($upload);
        $postBytes = ini_parse_quantity($post);
        $file = $uploadBytes > 0 ? min($application, $uploadBytes) : $application;
        // Allow multipart headers and biodata without changing backend validation.
        $total = $postBytes > 0 ? max(0, $postBytes - 128 * 1024) : 3 * $application;

        return ['file_bytes' => min($file, $total), 'total_bytes' => $total, 'file_label' => rtrim(rtrim(number_format(min($file, $total) / 1024 / 1024, 2, '.', ''), '0'), '.').' MB'];
    }
}
