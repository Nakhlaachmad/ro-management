<?php

// Synthetic, intentionally public test keys. NEVER use these keys outside tests.
require __DIR__.'/../../vendor/autoload.php';
foreach (['rsa', 'rsa-other'] as $name) {
    $options = ['config' => __DIR__.'/openssl.cnf', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $key = openssl_pkey_new($options);
    if ($key === false || ! openssl_pkey_export($key, $private, null, $options)) {
        throw new RuntimeException('Synthetic RSA generation failed.');
    }
    $details = openssl_pkey_get_details($key);
    file_put_contents(__DIR__."/{$name}-private.pem", $private);
    file_put_contents(__DIR__."/{$name}-public.pem", $details['key']);
    if ($name === 'rsa') {
        $base64url = fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        file_put_contents(__DIR__.'/jwks.json', json_encode(['keys' => [[
            'kty' => 'RSA', 'kid' => 'test-key', 'use' => 'sig', 'alg' => 'RS256',
            'n' => $base64url($details['rsa']['n']), 'e' => $base64url($details['rsa']['e']),
        ]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}
