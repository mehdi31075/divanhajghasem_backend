<?php

return [
    'token_ttl' => (int) env('DIVAN_TOKEN_TTL', 86400),
    'require_https' => (bool) env('DIVAN_REQUIRE_HTTPS', true),
];
