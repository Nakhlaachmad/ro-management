<?php

declare(strict_types=1);

namespace App\Infrastructure\FP;

use InvalidArgumentException;
use RuntimeException;

/** Builds the private, human-readable Nextcloud locator for an FP document. */
final class FpDocumentPath
{
    public const ALIAS_PREFIX = "FP-DOC-ALIAS-1\n";

    private const FILE_NAMES = [
        'PROFILE_PHOTO' => 'foto-profil.jpg',
        'KTP' => 'ktp.jpg',
        'IJAZAH' => 'ijazah.jpg',
    ];

    private const MAX_FOLDER_BYTES = 120;

    private const MAX_VARIANT = 20;

    /** Sanitizes biodata name into a safe folder segment without changing readable text. */
    public static function folder(string $fullName): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $fullName) ?? '';
        $value = str_replace(['/', '\\'], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        $value = preg_replace("/[^\\p{L}\\p{N} .'()_-]/u", '', $value) ?? '';
        $value = preg_replace('/\.{2,}/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        $value = trim($value, " .\t\n\r\0\x0B");
        if ($value === '' || $value === '.') {
            return 'FP';
        }
        if (strlen($value) > self::MAX_FOLDER_BYTES) {
            $value = rtrim(mb_strcut($value, 0, self::MAX_FOLDER_BYTES, 'UTF-8'));
        }

        return $value === '' ? 'FP' : $value;
    }

    public static function file(string $type): string
    {
        $file = self::FILE_NAMES[strtoupper($type)] ?? null;
        if ($file === null) {
            throw new InvalidArgumentException('Unsupported FP document type');
        }

        return $file;
    }

    public static function friendly(string $fullName, string $type, int $variant = 1): string
    {
        $folder = self::folder($fullName);
        if ($variant > 1) {
            $folder .= ' ('.$variant.')';
        }

        return self::friendlyForFolder($folder, $type);
    }

    /** Folder assigned only after approval, when the immutable NIP is known. */
    public static function approvedFolder(string $fullName, int|string $nip): string
    {
        $nip = (string) $nip;
        if (preg_match('/\A[0-9]{1,20}\z/', $nip) !== 1) {
            throw new InvalidArgumentException('Invalid FP NIP');
        }
        $suffix = '_'.$nip;
        $name = self::folder($fullName);
        if (strlen($name) + strlen($suffix) > self::MAX_FOLDER_BYTES) {
            $name = rtrim(mb_strcut($name, 0, self::MAX_FOLDER_BYTES - strlen($suffix), 'UTF-8'), " .\t");
            if ($name === '') {
                $name = 'FP';
            }
        }

        return $name.$suffix;
    }

    public static function approved(string $fullName, int|string $nip, string $type): string
    {
        return self::friendlyForFolder(self::approvedFolder($fullName, $nip), $type);
    }

    public static function friendlyForFolder(string $folder, string $type): string
    {
        if (! self::validFolder($folder)) {
            throw new InvalidArgumentException('Unsafe FP document folder');
        }

        return 'fp/'.$folder.'/'.self::file($type);
    }

    public static function isFriendly(string $path): bool
    {
        if (preg_match('~\Afp/(.+)/(ktp|ijazah|foto-profil)[.]jpg\z~u', $path, $matches) !== 1) {
            return false;
        }

        return self::validFolder($matches[1]);
    }

    /** @return list<string> */
    public static function variantFolders(string $fullName): array
    {
        $base = self::folder($fullName);
        $folders = [$base];
        for ($variant = 2; $variant <= self::MAX_VARIANT; $variant++) {
            $folders[] = $base.' ('.$variant.')';
        }

        return $folders;
    }

    public static function aliasPayload(string $friendlyPath): string
    {
        if (! self::isFriendly($friendlyPath)) {
            throw new InvalidArgumentException('Unsafe FP document locator');
        }

        return self::ALIAS_PREFIX.$friendlyPath."\n";
    }

    public static function aliasTarget(string $bytes): ?string
    {
        if (! str_starts_with($bytes, self::ALIAS_PREFIX)) {
            return null;
        }
        $path = trim(substr($bytes, strlen(self::ALIAS_PREFIX)));
        if (! self::isFriendly($path)) {
            throw new RuntimeException('FP document alias invalid');
        }

        return $path;
    }

    private static function validFolder(string $folder): bool
    {
        if ($folder === '' || $folder === '.' || $folder === '..' || strlen($folder) > self::MAX_FOLDER_BYTES + 12) {
            return false;
        }
        if (preg_match('/[\x00-\x1F\x7F\\\\]/u', $folder) === 1 || str_starts_with($folder, '.') || str_ends_with($folder, '.')) {
            return false;
        }

        return preg_match("/\\A[\\p{L}\\p{N} .'()_-]+\\z/u", $folder) === 1;
    }
}
