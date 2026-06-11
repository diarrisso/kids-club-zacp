<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // Tightenable per-deployment without a code change: set
    // WIDGET_ALLOWED_ORIGINS="https://praxis-domain.de" (comma-separated for
    // several) in the prod .env once the WP embed domain is final. Wildcard
    // default is acceptable: anonymous read API, supports_credentials=false.
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env('WIDGET_ALLOWED_ORIGINS', '*'))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
