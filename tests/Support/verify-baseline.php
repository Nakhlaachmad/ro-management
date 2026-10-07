<?php

declare(strict_types=1);

// Read-only: no Laravel bootstrap, no .env, no DB or HTTP access.
$root = dirname(__DIR__, 2);
$baseline = $root.'/tests/Fixtures/u0-source-hashes.json';
if (! is_file($baseline) || hash_file('sha256', $baseline) !== '7834762741dc0ab30958aff23a4da47ba76d777dbff9ffa5715d1b2ac3159000') {
    fwrite(STDERR, 'Accepted baseline fixture changed: tests/Fixtures/u0-source-hashes.json; STOP. Do not regenerate integration hashes.'.PHP_EOL);
    exit(1);
}
$hashes = json_decode(file_get_contents($baseline), true, 512, JSON_THROW_ON_ERROR);
$platformFiles = ['composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'Dockerfile', 'docker-compose.dokploy.yml'];
$integrationCount = 0;
foreach ($hashes as $file => $expected) {
    if (in_array($file, $platformFiles, true)) {
        continue;
    }
    if (! is_file($root.'/'.$file) || hash_file('sha256', $root.'/'.$file) !== $expected) {
        fwrite(STDERR, 'Immutable integration source differs: '.$file.'; STOP.'.PHP_EOL);
        exit(1);
    }
    $integrationCount++;
}
echo 'PASS: '.$integrationCount.' immutable integration hashes; accepted U0/U1 fixture unchanged.'.PHP_EOL;

// Read-only evidence: never generate snapshots or treat a manifest match as regression approval.
$checkpoints = json_decode(file_get_contents($root.'/tests/Fixtures/platform-checkpoints.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($platformFiles as $file) {
    if (($checkpoints['snapshots']['U1']['files'][$file] ?? null) !== $hashes[$file]) {
        fwrite(STDERR, 'Accepted U1 platform evidence changed: '.$file.PHP_EOL);
        exit(1);
    }
}
foreach ($checkpoints['snapshots'] as $checkpoint => $snapshot) {
    $differences = [];
    foreach ($platformFiles as $file) {
        $actual = is_file($root.'/'.$file) ? hash_file('sha256', $root.'/'.$file) : null;
        if ($actual !== ($snapshot['files'][$file] ?? null)) {
            $differences[] = $file;
        }
    }
    if ($differences === []) {
        echo 'EVIDENCE: platform manifests match recorded '.$checkpoint.' snapshot; this is not a current regression result.'.PHP_EOL;
    } else {
        echo 'UNVALIDATED PLATFORM CHANGE relative to '.$checkpoint.': '.implode(', ', $differences).'. Run complete regression gates before recording a new checkpoint.'.PHP_EOL;
    }
}

// Installation consistency against the CURRENT lockfile, not an immutable package-version contract.
$lock = json_decode(file_get_contents($root.'/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$installedPath = $root.'/vendor/composer/installed.json';
if (! is_file($installedPath)) {
    fwrite(STDERR, "Missing vendor: install the current reviewed composer.lock before verifying.\n");
    exit(1);
}
$installed = json_decode(file_get_contents($installedPath), true, 512, JSON_THROW_ON_ERROR);
$versions = array_column($installed['packages'] ?? $installed, 'version', 'name');
foreach (array_merge($lock['packages'], $lock['packages-dev']) as $package) {
    if (($versions[$package['name']] ?? null) !== $package['version']) {
        fwrite(STDERR, 'Installed package differs/missing: '.$package['name'].PHP_EOL);
        exit(1);
    }
}
echo 'PASS: all current locked production/dev packages match installed versions (checkpoint consistency only).'.PHP_EOL;
echo 'PHP '.PHP_VERSION.'; PDO SQLite: '.(extension_loaded('pdo_sqlite') ? 'yes' : 'no').'; OpenSSL: '.(extension_loaded('openssl') ? 'yes' : 'no').PHP_EOL;
