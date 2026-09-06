<?php

declare(strict_types=1);

$audiences = preg_split('/\s*,\s*/', trim((string) env('API_JWT_AUDIENCES', 'ro-service,iwacs-api')));

return [
    'issuer' => rtrim((string) env('API_JWT_ISSUER', env('OIDC_ISSUER', 'http://localhost:8080/realms/vif')), '/'),
    'discovery_url' => (string) env('API_JWT_DISCOVERY_URL', env('OIDC_DISCOVERY_URL', 'http://localhost:8080/realms/vif/.well-known/openid-configuration')),
    'audiences' => is_array($audiences) ? array_values(array_filter($audiences)) : [],
    'leeway_seconds' => (int) env('API_JWT_LEEWAY_SECONDS', 5),
    'jwks_cache_seconds' => (int) env('API_JWT_JWKS_CACHE_SECONDS', 3600),
    'connect_timeout' => (float) env('API_CONNECT_TIMEOUT', 5),
    'timeout' => (float) env('API_TIMEOUT', 10),
];
