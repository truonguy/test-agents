<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
| Auth theo Bearer token (stateless) → không dùng cookie, supports_credentials=false.
| Origins cho 2 frontend (shop + crm) cấu hình qua env CORS_ALLOWED_ORIGINS
| (phân tách bằng dấu phẩy). ⚠️ Domain thật do hạ tầng cung cấp (Open Question §9.3).
*/

$origins = array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
));

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins ?: ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
