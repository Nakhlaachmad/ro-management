<?php

declare(strict_types=1);

// FP activation is an explicit operator opt-in and is not restricted to local.
$fpEnabled = env('FP_ENABLED', false);

$nextcloudBaseUrl = (string) env('NEXTCLOUD_BASE_URL', '');
$nextcloudUsername = (string) env('NEXTCLOUD_USERNAME', '');
$nextcloudRoot = (string) env('NEXTCLOUD_ROOT_FOLDER', 'Victory-FP-Management');
$nextcloudEndpoint = env('FP_NEXTCLOUD_ENDPOINT');
if (! $nextcloudEndpoint && $nextcloudBaseUrl !== '' && $nextcloudUsername !== ''
    && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,99}\z/', $nextcloudRoot)) {
    $nextcloudEndpoint = rtrim($nextcloudBaseUrl, '/').'/remote.php/dav/files/'.rawurlencode($nextcloudUsername).'/'.$nextcloudRoot;
}

return [
    // F2.1 is schema preparation only. F2.2 invariants and application review are required.
    'enabled' => $fpEnabled,
    'core_schema_only' => ! $fpEnabled,
    'user_management_enabled' => $fpEnabled && env('FP_USER_MANAGEMENT_ENABLED', false), // Explicit activation after migration and grants.
    'business_timezone' => env('FP_BUSINESS_TIMEZONE'),
    'nik_lookup_key' => env('FP_NIK_LOOKUP_KEY'),
    'nik_lookup_key_id' => env('FP_NIK_LOOKUP_KEY_ID'),
    'annual_nip_email_enabled' => $fpEnabled && env('FP_ANNUAL_NIP_EMAIL_ENABLED', false),
    'user_roles_enabled' => $fpEnabled && env('FP_USER_ROLES_ENABLED', false),
    'recruitment_team_enabled' => $fpEnabled && env('FP_RECRUITMENT_TEAM_ENABLED', false),
    'organization_enabled' => $fpEnabled && env('FP_ORGANIZATION_ENABLED', false),
    // Enable only after the F3.7H schema and acceptance gates have passed.
    'optional_onboarding_enabled' => $fpEnabled && env('FP_OPTIONAL_ONBOARDING_ENABLED', false),
    'first_nip' => 1001,
    // Set to nextcloud only when the operator has accepted private remote storage.
    'storage_adapter' => env('FP_STORAGE_ADAPTER', 'local'),
    // Legacy adapter consumes FP_NEXTCLOUD_* when present; otherwise it reuses the
    // same service account configured for the F3.9 read-only health client.
    'nextcloud' => [
        'endpoint' => $nextcloudEndpoint,
        'user' => env('FP_NEXTCLOUD_USER') ?: $nextcloudUsername,
        'password' => env('FP_NEXTCLOUD_PASSWORD') ?: env('NEXTCLOUD_APP_PASSWORD'),
    ],
];
