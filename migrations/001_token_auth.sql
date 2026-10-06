-- Additive migration: run once in the EXISTING hosting database.
-- No ALTER/UPDATE/DROP on tbl_user, tbl_news or tbl_news_category.
CREATE TABLE IF NOT EXISTS divan_api_tokens (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    username VARCHAR(255) NOT NULL,
    password_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at INT UNSIGNED NOT NULL,
    expires_at INT UNSIGNED NOT NULL,
    KEY token_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS divan_api_login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempted_at INT UNSIGNED NOT NULL,
    KEY login_window (ip_hash, attempted_at),
    KEY attempt_expiry (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
