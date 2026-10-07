<?php

declare(strict_types=1);

// Read-only: no Laravel bootstrap, no .env, no DB or HTTP access.
$root = dirname(__DIR__, 2);
$hashes = json_decode(file_get_contents($root.'/tests/Fixtures/u0-source-hashes.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($hashes as $file => $expected) {
    if (! is_file($root.'/'.$file) || hash_file('sha256', $root.'/'.$file) !== $expected) {
        fwrite(STDERR, 'U0 source differs: '.$file.PHP_EOL);
        exit(1);
    }
}
$lock = json_decode(file_get_contents($root.'/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$installedPath = $root.'/vendor/composer/installed.json';
if (! is_file($installedPath)) {
    fwrite(STDERR, "Missing vendor: install unchanged composer.lock before verifying.\n");
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
echo 'PASS: '.count($hashes).' frozen file hashes; all locked production/dev packages match installed versions.'.PHP_EOL;
echo 'PHP '.PHP_VERSION.'; PDO SQLite: '.(extension_loaded('pdo_sqlite') ? 'yes' : 'no').'; OpenSSL: '.(extension_loaded('openssl') ? 'yes' : 'no').PHP_EOL;
