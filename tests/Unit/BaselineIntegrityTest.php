<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BaselineIntegrityTest extends TestCase
{
    public function test_integration_is_frozen_and_u1_platform_snapshot_preserves_checkpoint_evidence(): void
    {
        $baseline = __DIR__.'/../Fixtures/u0-source-hashes.json';
        $this->assertSame(
            '7834762741dc0ab30958aff23a4da47ba76d777dbff9ffa5715d1b2ac3159000',
            hash_file('sha256', $baseline),
            'Accepted U0/U1 hash fixture changed; stop rather than regenerate integration expectations.'
        );
        $hashes = json_decode(file_get_contents($baseline), true, 512, JSON_THROW_ON_ERROR);
        $platformFiles = ['composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'Dockerfile', 'docker-compose.dokploy.yml'];
        $checkpoints = json_decode(file_get_contents(__DIR__.'/../Fixtures/platform-checkpoints.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($hashes as $file => $hash) {
            if (in_array($file, $platformFiles, true)) {
                // Historical evidence only. The current manifest may change during an approved upgrade.
                $this->assertSame($hash, $checkpoints['snapshots']['U1']['files'][$file] ?? null, 'Accepted U1 platform evidence changed: '.$file);

                continue;
            }

            $this->assertSame($hash, is_file(__DIR__.'/../../'.$file) ? hash_file('sha256', __DIR__.'/../../'.$file) : null, 'Immutable integration source changed; stop: '.$file);
        }
    }
}
