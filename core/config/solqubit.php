<?php

return [
    'version' => '1.0.0',
    'content_schema' => 1,
    'installed_file' => storage_path('app/installed.json'),
    'themes_path' => base_path('themes'),
    'installation_database_host' => env('SOLQUBIT_INSTALL_DB_HOST', 'localhost'),
    'installation_database_name' => env('SOLQUBIT_INSTALL_DB_NAME', ''),
    'installation_database_user' => env('SOLQUBIT_INSTALL_DB_USER', ''),
    'public_path' => public_path(),
    'max_upload_kb' => 32768,
    'admin_path_override' => env('SOLQUBIT_ADMIN_PATH'),
    'admin_access_file' => env('SOLQUBIT_ADMIN_ACCESS_FILE', storage_path('app/admin-access.json')),
    // Compact copy of DB-IP's country list, downloaded from the statistics page.
    'country_database_file' => storage_path('app/geoip/countries.bin'),
    // GitHub repository trusted for updates. When unset, the one recorded in the installed package is used.
    'update_repository' => env('SOLQUBIT_UPDATE_REPOSITORY'),
    // Server-side escape hatch for local development or private builds: accept ZIPs not published on GitHub.
    'allow_unverified' => (bool) env('SOLQUBIT_ALLOW_UNVERIFIED', false),
];
