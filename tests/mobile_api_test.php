<?php
require __DIR__.'/fixture_store.php';
$checks = 0;
function check($value, $label) {
    global $checks;
    if (!$value) throw new RuntimeException('FAILED: '.$label);
    $checks++;
}
function rejected($callback, $status, $label) {
    try { $callback(); } catch (DivanApiError $e) {
        check($e->status === $status, $label); return;
    }
    throw new RuntimeException('FAILED: expected rejection: '.$label);
}
$store = new FixtureStore();
$api = new DivanMobileApi($store, 3600);
$now = 2000000000;
$call = function($method, $action, $data = array(), $token = null, $at = null) use ($api, $now) {
    return $api->handle($method, $action, $data, $token, 'fixture-ip', $at === null ? $now : $at);
};
$input = array('news_heading' => 'عنوان فارسی', 'news_date' => 'زیرعنوان', 'news_description' => '<p dir="rtl"><strong>متن</strong></p>', 'cid' => '61');
check(count($call('GET', '')['AndroidEbookApp']) === 2, 'public categories');
check($call('GET', '', array('nid' => 999)) === array(), 'legacy empty array');
rejected(function() use ($call, $input) { $call('POST', 'create', $input); }, 401, 'cookie-free authorization mandatory');
rejected(function() use ($call) { $call('GET', 'login'); }, 405, 'login cannot be GET');
rejected(function() use ($call) { $call('POST', 'login', array('username' => 'fixture-admin', 'password' => 'wrong')); }, 401, 'wrong credentials');
check(count($store->rows('SELECT * FROM divan_api_tokens')) === 0, 'failed login issues no token');
$login = $call('POST', 'login', array('username' => ' FIXTURE-ADMIN ', 'password' => 'fixture-password'));
$token = $login['access_token'];
check(strlen($token) === 64 && $login['token_type'] === 'Bearer', 'random bearer token');
check($login['expires_in'] === 3600, 'bounded expiry');
$stored = $store->rows('SELECT * FROM divan_api_tokens')[0];
check($stored['token_hash'] === hash('sha256', $token) && $stored['token_hash'] !== $token, 'only hashed token stored');
check($call('GET', 'me', array(), $token)['username'] === 'fixture-admin', 'token authenticates');
rejected(function() use ($call, $token) { $call('GET', 'me', array(), str_repeat('a', 64)); }, 401, 'forged token');
rejected(function() use ($call, $token) { $call('GET', 'me', array(), $token, 2000003600); }, 401, 'expiry boundary');
rejected(function() use ($call, $token) { $call('GET', 'delete', array('id' => '1'), $token); }, 405, 'read cannot mutate');
$created = $call('POST', 'create', $input, $token);
check($created['ok'] === true && (int)$created['nid'] > 0, 'create real fixture row');
$id = $created['nid'];
$record = $call('GET', '', array('nid' => $id))['AndroidEbookApp'][0];
check($record['news_description'] === $input['news_description'] && $record['news_image'] === '', 'HTML preserved and strict image field supported');
check(is_string($record['nid']) && $record['category_image'] === 'image.png', 'legacy wire format');
$changed = $input; $changed['id'] = $id; $changed['cid'] = '62'; $changed['news_heading'] = 'ویرایش';
check($call('POST', 'update', $changed, $token)['ok'], 'update');
check($call('GET', '', array('nid' => $id))['AndroidEbookApp'][0]['cat_id'] === '62', 'category moved');
check($call('POST', 'update', $changed, $token)['ok'], 'unchanged update remains success');
$bad = $changed; $bad['cid'] = '999';
rejected(function() use ($call, $bad, $token) { $call('POST', 'update', $bad, $token); }, 422, 'nonexistent category');
$bad = $changed; $bad['id'] = '1 OR 1=1';
rejected(function() use ($call, $bad, $token) { $call('POST', 'update', $bad, $token); }, 422, 'injection rejected');
$bad = $changed; $bad['id'] = '999';
rejected(function() use ($call, $bad, $token) { $call('POST', 'update', $bad, $token); }, 404, 'missing article');
$bad = $input; $bad['news_date'] = ' ';
rejected(function() use ($call, $bad, $token) { $call('POST', 'create', $bad, $token); }, 422, 'required subtitle');
$bad = $input; $bad['news_date'] = str_repeat('آ', 256);
rejected(function() use ($call, $bad, $token) { $call('POST', 'create', $bad, $token); }, 422, 'legacy varchar character limit');
$bad = $input; $bad['news_description'] = str_repeat('a', 65536);
rejected(function() use ($call, $bad, $token) { $call('POST', 'create', $bad, $token); }, 422, 'legacy TEXT byte limit');
$bad = $input; $bad['news_description'] = '😀';
rejected(function() use ($call, $bad, $token) { $call('POST', 'create', $bad, $token); }, 422, 'unsupported utf8mb4 never silently truncated');
check(count($store->rows('SELECT * FROM tbl_news')) === 1, 'rejected creates made no rows');
check($call('POST', 'delete', array('id' => $id), $token)['ok'], 'delete');
check($call('GET', '', array('nid' => $id)) === array(), 'deletion visible to legacy-shaped readers');
check($call('POST', 'delete', array('id' => $id), $token)['ok'], 'delete idempotent');
$second = $call('POST', 'login', array('username' => 'fixture-admin', 'password' => 'fixture-password'))['access_token'];
check($second !== $token, 'separate login tokens');
check($call('POST', 'logout', array(), $token)['ok'], 'logout revokes this token');
rejected(function() use ($call, $token) { $call('GET', 'me', array(), $token); }, 401, 'revoked token rejected');
check($call('GET', 'me', array(), $second)['ok'], 'logout leaves other clients logged in');
$store->exec("UPDATE tbl_user SET Password = 'changed-password-hash'");
rejected(function() use ($call, $second) { $call('GET', 'me', array(), $second); }, 401, 'panel password change invalidates tokens');
for ($i = 0; $i < 9; $i++) {
    rejected(function() use ($call) { $call('POST', 'login', array('username' => 'missing', 'password' => 'wrong')); }, 401, 'failed attempt '.$i);
}
rejected(function() use ($call) { $call('POST', 'login', array('username' => 'missing', 'password' => 'wrong')); }, 429, 'IP rate limited');
rejected(function() use ($call) { $call('POST', 'login', array('username' => 'missing', 'password' => 'wrong'), null, 2000000901); }, 401, 'rate window expires');
// Old panel sources remain byte-identical; migration never changes their schema.
$migration = file_get_contents(__DIR__.'/../migrations/001_token_auth.sql');
check(!preg_match('/^\s*(ALTER|UPDATE|DROP)\b/im', $migration), 'only additive migration');
check(strpos(file_get_contents(__DIR__.'/../mobile-api.php'), 'session_start') === false, 'new endpoint never creates cookies');
echo 'PASS: '.$checks." backend checks (production SQL on isolated SQLite fixture).\n";
