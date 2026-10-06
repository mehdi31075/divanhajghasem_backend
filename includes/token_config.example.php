<?php
// Copy to token_config.php. Local/hosting config is ignored by Git.
return array(
    'token_ttl' => 86400, // 24 hours; server clamps to 60..604800 seconds.
    'require_https' => true,
    // For an HTTP-only test host ONLY, explicitly set require_https to false.
);
