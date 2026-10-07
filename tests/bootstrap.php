<?php

declare(strict_types=1);

// Test-only entry point: override inherited settings before Laravel reads config.
require __DIR__.'/../vendor/autoload.php';

$runtime = sys_get_temp_dir().'/ro-characterization-'.getmypid();
if (! is_dir($runtime) && ! mkdir($runtime, 0700, true) && ! is_dir($runtime)) {
    throw new RuntimeException('Cannot create isolated test runtime.');
}

$environment = [
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:'.base64_encode(str_repeat('t', 32)),
    'APP_URL' => 'http://localhost',
    'APP_CONFIG_CACHE' => $runtime.'/config-never-generated.php',
    'APP_ROUTES_CACHE' => $runtime.'/routes-never-generated.php',
    'APP_SERVICES_CACHE' => $runtime.'/services.php',
    'APP_PACKAGES_CACHE' => $runtime.'/packages.php',
    'VIEW_COMPILED_PATH' => $runtime,
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'DATABASE_URL' => '',
    'DB_HOST' => '127.0.0.1',
    'DB_USERNAME' => '',
    'DB_PASSWORD' => '',
    'CACHE_DRIVER' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
    'LOG_CHANNEL' => 'null',
    'BCRYPT_ROUNDS' => '4',
    'IWACS_RO_SYNC_ENABLED' => 'false',
    'IWACS_RO_SYNC_CLIENT_SECRET' => 'synthetic-test-secret',
    'SSO_PUBLIC_KEY_PATH' => $runtime.'/no-production-key.pem',
    'SSO_EXPECTED_ISSUER' => 'https://iwacs.test',
    'API_JWT_ISSUER' => 'https://keycloak.test/realms/test',
    'API_JWT_DISCOVERY_URL' => 'https://keycloak.test/discovery',
    'RO_CHARACTERIZATION_RUNTIME' => $runtime,
];
foreach ($environment as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}

if (! extension_loaded('pdo_sqlite') || ! extension_loaded('openssl')) {
    throw new RuntimeException('Isolation requires pdo_sqlite and synthetic JWT fixtures require openssl.');
}
if (is_file($environment['APP_CONFIG_CACHE']) || is_file($environment['APP_ROUTES_CACHE'])) {
    throw new RuntimeException('Refusing cached configuration/routes in characterization runtime.');
}
