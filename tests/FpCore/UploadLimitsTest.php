<?php

declare(strict_types=1);

namespace Tests\FpCore;

use App\Infrastructure\FP\UploadLimits;
use PHPUnit\Framework\TestCase;

final class UploadLimitsTest extends TestCase
{
    public function test_effective_file_limit_respects_local_transport(): void
    {
        $limits = UploadLimits::calculate('2M', '8M');
        self::assertSame(2 * 1024 * 1024, $limits['file_bytes']);
        self::assertSame('2 MB', $limits['file_label']);
        self::assertGreaterThan(3 * $limits['file_bytes'], $limits['total_bytes']);
    }

    public function test_larger_transport_does_not_expand_application_contract(): void
    {
        self::assertSame(8 * 1024 * 1024, UploadLimits::calculate('32M', '64M')['file_bytes']);
    }

    public function test_post_limit_reserves_multipart_biodata_capacity(): void
    {
        self::assertSame(1024 * 1024 - 128 * 1024, UploadLimits::calculate('8M', '1M')['total_bytes']);
    }
}
