<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
     * RO -> IWACS is a service-to-service link. Credentials are supplied only
     * through the deployed environment; an incomplete configuration fails
     * closed so a local RO edit cannot silently drift from IWACS.
     */
    'iwacs_ro' => [
        'enabled' => filter_var(env('IWACS_RO_SYNC_ENABLED', false), FILTER_VALIDATE_BOOL),
        'url' => rtrim((string) env('IWACS_RO_SYNC_API_URL', ''), '/'),
        'lookup_url' => rtrim((string) env('IWACS_RO_LOOKUP_API_URL', ''), '/'),
        'discovery_url' => env('IWACS_RO_SYNC_DISCOVERY_URL', env('API_JWT_DISCOVERY_URL', '')),
        'client_id' => env('IWACS_RO_SYNC_CLIENT_ID', 'ro-service'),
        'client_secret' => env('IWACS_RO_SYNC_CLIENT_SECRET', ''),
        'connect_timeout' => (float) env('IWACS_RO_SYNC_CONNECT_TIMEOUT', 5),
        'timeout' => (float) env('IWACS_RO_SYNC_TIMEOUT', 10),
    ],

    /* RO validates the short-lived, RS256-signed browser handoff from IWACS. */
    'portal_sso' => [
        'issuer' => env('SSO_EXPECTED_ISSUER', env('PORTAL_APP_URL', 'http://localhost:8000')),
        'audience' => env('SSO_EXPECTED_AUDIENCE', 'ro'),
        'public_key_path' => env('SSO_PUBLIC_KEY_PATH', 'storage/app/keys/iwacs-public.pem'),
        'token_ttl' => (int) env('SSO_TOKEN_TTL', 120),
        'leeway' => (int) env('SSO_LEEWAY_SECONDS', 5),
        'replay_ttl' => (int) env('SSO_REPLAY_TTL', 120),
    ],

];
