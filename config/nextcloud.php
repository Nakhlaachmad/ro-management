<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('NEXTCLOUD_ENABLED', false),
    'base_url' => env('NEXTCLOUD_BASE_URL', 'https://cloud.viffx.com'),
    'username' => env('NEXTCLOUD_USERNAME', ''),
    'app_password' => env('NEXTCLOUD_APP_PASSWORD', ''),
    'root_folder' => env('NEXTCLOUD_ROOT_FOLDER', 'Victory-FP-Management'),
];
