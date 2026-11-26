<?php

declare(strict_types=1);

namespace Tests\FpStorage;

use App\Infrastructure\FP\FpDocumentPath;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FpDocumentPathTest extends TestCase
{
    public function test_biodata_name_is_kept_but_path_separators_and_controls_are_removed(): void
    {
        self::assertSame('Budi Santoso', FpDocumentPath::folder('  Budi/../Santoso  '));
        self::assertSame('Siti Aisyah', FpDocumentPath::folder("Siti\\Aisyah\n"));
        self::assertSame('FP', FpDocumentPath::folder('..'));
        self::assertSame('FP', FpDocumentPath::folder(''));
        self::assertLessThanOrEqual(120, strlen(FpDocumentPath::folder(str_repeat('a', 500))));
    }

    public function test_document_type_maps_to_requested_file_name(): void
    {
        self::assertSame('fp/Budi Santoso/ktp.jpg', FpDocumentPath::friendly('Budi Santoso', 'KTP'));
        self::assertSame('fp/Budi Santoso/ijazah.jpg', FpDocumentPath::friendly('Budi Santoso', 'IJAZAH'));
        self::assertSame('fp/Budi Santoso (2)/ktp.jpg', FpDocumentPath::friendly('Budi Santoso', 'KTP', 2));
    }

    public function test_approval_folder_uses_the_final_name_and_nip(): void
    {
        self::assertSame('Budi Santoso_1001', FpDocumentPath::approvedFolder('  Budi/Santoso  ', 1001));
        self::assertSame('fp/Budi Santoso_1001/ktp.jpg', FpDocumentPath::approved('Budi Santoso', 1001, 'KTP'));
        self::assertSame('fp/Budi Santoso_20261001/ijazah.jpg', FpDocumentPath::approved('Budi Santoso', '20261001', 'IJAZAH'));
        self::assertTrue(FpDocumentPath::isFriendly('fp/Budi Santoso_1001/ktp.jpg'));
        self::assertLessThanOrEqual(120 + 12, strlen(FpDocumentPath::approvedFolder(str_repeat('a', 500), 1001)));
    }

    public function test_invalid_nip_cannot_shape_a_folder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FpDocumentPath::approvedFolder('Budi Santoso', '../1001');
    }

    public function test_unsafe_friendly_locators_are_rejected(): void
    {
        self::assertTrue(FpDocumentPath::isFriendly('fp/Budi Santoso/ktp.jpg'));
        self::assertFalse(FpDocumentPath::isFriendly('../secret/ktp.jpg'));
        self::assertFalse(FpDocumentPath::isFriendly('fp/../ktp.jpg'));
        self::assertFalse(FpDocumentPath::isFriendly('fp/Budi/Santoso/ktp.jpg'));
        self::assertFalse(FpDocumentPath::isFriendly('fp/Budi/passport.jpg'));
    }

    public function test_alias_round_trip_is_safe(): void
    {
        $friendly = FpDocumentPath::friendly('Budi Santoso', 'IJAZAH');
        $payload = FpDocumentPath::aliasPayload($friendly);

        self::assertSame($friendly, FpDocumentPath::aliasTarget($payload));
        self::assertNull(FpDocumentPath::aliasTarget('binary image bytes'));
    }

    public function test_invalid_alias_is_rejected(): void
    {
        $payload = FpDocumentPath::ALIAS_PREFIX."../secret\n";
        $this->expectException(RuntimeException::class);
        FpDocumentPath::aliasTarget($payload);
    }

    public function test_unsupported_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FpDocumentPath::friendly('Budi Santoso', 'PASSPORT');
    }
}
