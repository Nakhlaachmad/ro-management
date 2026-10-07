<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BaselineIntegrityTest extends TestCase
{
    public function test_protected_source_and_platform_manifests_equal_u0_baseline(): void
    {
        $hashes = json_decode(file_get_contents(__DIR__.'/../Fixtures/u0-source-hashes.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($hashes as $file => $hash) {
            $this->assertSame($hash, hash_file('sha256', __DIR__.'/../../'.$file), 'U0 frozen source changed: '.$file);
        }
    }
}
