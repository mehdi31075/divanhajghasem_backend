<?php
// Independent JSON endpoint. Existing api.php/panel files remain unchanged.
ob_start(); // Legacy hosting config may contain trailing whitespace.
ini_set('display_errors', '0');
require_once __DIR__.'/includes/mobile_api.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
// No cookies/credentials: public readers and explicit Bearer headers use CORS.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Cache-Control');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function divan_emit($status, $body) {
    if (ob_get_length() !== false) ob_clean();
    http_response_code($status);
    if ($status === 401) header('WWW-Authenticate: Bearer');
    if ($status === 429) header('Retry-After: 900');
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        http_response_code(500);
        echo '{"ok":false,"error":"encoding_failed","message":"Invalid database text"}';
    } else { echo $json; }
    exit;
}

try {
    $action = isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : '';
    $config = array('require_https' => true, 'token_ttl' => 86400);
    if (is_file(__DIR__.'/includes/token_config.php')) {
        $custom = require __DIR__.'/includes/token_config.php';
        if (is_array($custom)) $config = array_merge($config, $custom);
    }
    // Behind TLS termination, set this in hosting config; do not trust arbitrary
    // X-Forwarded-Proto headers supplied by the client.
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' && $_SERVER['HTTPS'] !== '';
    if ($action !== '' && $config['require_https'] && !$secure) {
        throw new DivanApiError(426, 'https_required', 'ورود و مدیریت باید از HTTPS انجام شود.');
    }
    $method = $_SERVER['REQUEST_METHOD'];
    $input = $method === 'GET' ? $_GET : $_POST;
    if ($method === 'POST') {
        if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 1100000) {
            throw new DivanApiError(413, 'request_too_large', 'درخواست بیش از حد بزرگ است.');
        }
        $type = isset($_SERVER['CONTENT_TYPE']) ? strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'])[0])) : '';
        if ($type === 'application/json') {
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input) || json_last_error() !== JSON_ERROR_NONE) {
                throw new DivanApiError(400, 'invalid_json', 'بدنه JSON معتبر نیست.');
            }
        } elseif ($type !== 'application/x-www-form-urlencoded') {
            throw new DivanApiError(415, 'unsupported_content_type', 'نوع بدنه درخواست معتبر نیست.');
        }
    }
    $authorization = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] :
        (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : '');
    if ($authorization === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) {
            if (strtolower($key) === 'authorization') $authorization = $value;
        }
    }
    $bearer = preg_match('/^Bearer ([a-f0-9]{64})$/i', trim($authorization), $match) ? $match[1] : null;
    // Use the host's existing mysqli connection; no password is committed.
    require __DIR__.'/includes/variables.php';
    if (!isset($connect) || $connect->connect_errno || !$connect->set_charset('utf8')) {
        throw new RuntimeException('Database connection failed');
    }
    if (!$connect->query("SET SESSION sql_mode = 'STRICT_ALL_TABLES'")) throw new RuntimeException('Cannot enforce database validation');
    $api = new DivanMobileApi(new DivanMySqlStore($connect), $config['token_ttl']);
    $body = $api->handle($method, $action, $input, $bearer, isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown');
    $connect->close();
    divan_emit(200, $body);
} catch (DivanApiError $error) {
    divan_emit($error->status, array('ok' => false, 'error' => $error->errorCode, 'message' => $error->getMessage()));
} catch (Exception $error) {
    // Do not disclose database names, credentials, SQL or submitted content.
    error_log('Divan mobile API failure: '.get_class($error));
    divan_emit(500, array('ok' => false, 'error' => 'server_error', 'message' => 'API آماده نیست؛ اتصال دیتابیس و اجرای migration را بررسی کنید.'));
} catch (Throwable $error) {
    divan_emit(500, array('ok' => false, 'error' => 'server_error', 'message' => 'خطای داخلی سرور.'));
}
