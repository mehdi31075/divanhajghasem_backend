<?php

return [
    'paths' => ['api.php', 'mobile-api.php'],
    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
    'allowed_origins' => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'Cache-Control'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => false,
];
