<?php

require __DIR__.'/fixture_store.php';
$checks = 0;
function check($value, $label)
{
    global $checks;
    if (! $value) {
        throw new RuntimeException('FAILED: '.$label);
    }
    $checks++;
}
function rejected($callback, $status, $label)
{
    try {
        $callback();
    } catch (DivanApiError $e) {
        check($e->status === $status, $label);

        return;
    }
    throw new RuntimeException('FAILED: expected rejection: '.$label);
}
$store = new FixtureStore;
$api = new DivanMobileApi($store, 3600);
$now = 2000000000;
$call = function ($method, $action, $data = [], $token = null, $at = null) use ($api, $now) {
    return $api->handle($method, $action, $data, $token, 'fixture-ip', $at === null ? $now : $at);
};
$input = ['news_heading' => 'عنوان فارسی', 'news_date' => 'زیرعنوان', 'news_description' => '<p dir="rtl"><strong>متن</strong></p>', 'cid' => '61'];
check(count($call('GET', '')['AndroidEbookApp']) === 2, 'public categories');
check($call('GET', '', ['nid' => 999]) === [], 'legacy empty array');
rejected(function () use ($call, $input) {
    $call('POST', 'create', $input);
}, 401, 'cookie-free authorization mandatory');
rejected(function () use ($call) {
    $call('GET', 'login');
}, 405, 'login cannot be GET');
rejected(function () use ($call) {
    $call('POST', 'login', ['username' => 'fixture-admin', 'password' => 'wrong']);
}, 401, 'wrong credentials');
check(count($store->rows('SELECT * FROM divan_api_tokens')) === 0, 'failed login issues no token');
$login = $call('POST', 'login', ['username' => ' FIXTURE-ADMIN ', 'password' => 'fixture-password']);
$token = $login['access_token'];
check(strlen($token) === 64 && $login['token_type'] === 'Bearer', 'random bearer token');
check($login['expires_in'] === 3600, 'bounded expiry');
$stored = $store->rows('SELECT * FROM divan_api_tokens')[0];
check($stored['token_hash'] === hash('sha256', $token) && $stored['token_hash'] !== $token, 'only hashed token stored');
check($call('GET', 'me', [], $token)['username'] === 'fixture-admin', 'token authenticates');
$supportStart = $call('POST', 'support_start', ['name' => 'کاربر آزمایشی', 'mobile' => '۰۹۱۲۳۴۵۶۷۸۹']);
check($supportStart['mode'] === 'register' && preg_match('/^[0-9]{6}$/', $supportStart['test_otp']), 'support registration creates a temporary OTP');
check(! isset($supportStart['receipt']), 'support registration has no tracking receipt');
check($store->supportUserByPhone('+989123456789')['name'] === 'کاربر آزمایشی', 'support account is keyed by normalized mobile');
rejected(function () use ($call) {
    $call('POST', 'support_send', ['message' => 'بدون حساب']);
}, 401, 'support message requires a signed-in account');
rejected(function () use ($call, $supportStart) {
    $call('POST', 'support_verify', ['challenge_id' => $supportStart['challenge_id'], 'otp' => '000000']);
}, 401, 'wrong support OTP rejected');
$supportLogin = $call('POST', 'support_verify', ['challenge_id' => $supportStart['challenge_id'], 'otp' => $supportStart['test_otp']]);
$supportToken = $supportLogin['access_token'];
check(strlen($supportToken) === 64 && $supportLogin['user']['mobile'] === '+989123456789', 'OTP verification returns a support-only bearer');
check($call('POST', 'support_send', ['message' => 'پیشنهاد فارسی'], $supportToken)['ok'], 'signed-in user can send a support message');
$myMessages = $call('GET', 'support_mine', [], $supportToken)['messages'];
check(count($myMessages) === 1 && $myMessages[0]['message'] === 'پیشنهاد فارسی', 'support account retrieves its own messages');
$ticketId = $myMessages[0]['id'];
check($call('POST', 'support_reply', ['id' => $ticketId, 'reply' => 'پاسخ اول مدیر'], $token)['ok'], 'admin can reply to support message');
check($call('POST', 'support_reply', ['id' => $ticketId, 'reply' => 'پاسخ دوم مدیر'], $token)['ok'], 'admin can send multiple replies');
check($call('POST', 'support_send', ['ticket_id' => $ticketId, 'message' => 'پاسخ کاربر در چت'], $supportToken)['ok'], 'user can reply in ticket conversation');
$chatMessages = $call('GET', 'support_mine', [], $supportToken)['messages'][0]['replies'];
check(count($chatMessages) === 3, 'all conversation replies returned in order');
check($chatMessages[0]['sender'] === 'admin' && $chatMessages[0]['message'] === 'پاسخ اول مدیر', 'first admin reply');
check($chatMessages[1]['sender'] === 'admin' && $chatMessages[1]['message'] === 'پاسخ دوم مدیر', 'second admin reply');
check($chatMessages[2]['sender'] === 'user' && $chatMessages[2]['message'] === 'پاسخ کاربر در چت', 'user reply');
$adminMessages = $call('GET', 'support_list', [], $token)['messages'];
check($adminMessages[0]['user_name'] === 'کاربر آزمایشی' && $adminMessages[0]['user_mobile'] === '+989123456789', 'admin can identify the support account');
check(count($adminMessages[0]['replies']) === 3, 'admin sees full conversation replies');
rejected(function () use ($call, $supportStart) {
    $call('POST', 'support_create', ['message' => 'بدون ورود']);
}, 410, 'receipt-based public support flow is retired');
check($call('POST', 'support_logout', [], $supportToken)['ok'], 'support logout revokes its token');
rejected(function () use ($call, $supportToken) {
    $call('GET', 'support_mine', [], $supportToken);
}, 401, 'revoked support token rejected');
rejected(function () use ($call) {
    $call('GET', 'me', [], str_repeat('a', 64));
}, 401, 'forged token');
rejected(function () use ($call, $token) {
    $call('GET', 'me', [], $token, 2000003600);
}, 401, 'expiry boundary');
rejected(function () use ($call, $token) {
    $call('GET', 'delete', ['id' => '1'], $token);
}, 405, 'read cannot mutate');
$created = $call('POST', 'create', $input, $token);
check($created['ok'] === true && (int) $created['nid'] > 0, 'create real fixture row');
$id = $created['nid'];
$record = $call('GET', '', ['nid' => $id])['AndroidEbookApp'][0];
check($record['news_description'] === $input['news_description'] && $record['news_image'] === '', 'HTML preserved and strict image field supported');
check(is_string($record['nid']) && $record['category_image'] === 'image.png', 'legacy wire format');
$changed = $input;
$changed['id'] = $id;
$changed['cid'] = '62';
$changed['news_heading'] = 'ویرایش';
check($call('POST', 'update', $changed, $token)['ok'], 'update');
check($call('GET', '', ['nid' => $id])['AndroidEbookApp'][0]['cat_id'] === '62', 'category moved');
check($call('POST', 'update', $changed, $token)['ok'], 'unchanged update remains success');
$bad = $changed;
$bad['cid'] = '999';
rejected(function () use ($call, $bad, $token) {
    $call('POST', 'update', $bad, $token);
}, 422, 'nonexistent category');
$bad = $changed;
$bad['id'] = '1 OR 1=1';
rejected(function () use ($call, $bad, $token) {
    $call('POST', 'update', $bad, $token);
}, 422, 'injection rejected');
$bad = $changed;
$bad['id'] = '999';
rejected(function () use ($call, $bad, $token) {
    $call('POST', 'update', $bad, $token);
}, 404, 'missing article');
$bad = $input;
$bad['news_date'] = ' ';
rejected(function () use ($call, $bad, $token) {
    $call('POST', 'create', $bad, $token);
}, 422, 'required subtitle');
$bad = $input;
$bad['news_date'] = str_repeat('آ', 256);
rejected(function () use ($call, $bad, $token) {
    $call('POST', 'create', $bad, $token);
}, 422, 'legacy varchar character limit');
$bad = $input;
$bad['news_description'] = str_repeat('a', 5000001);
rejected(function () use ($call, $bad, $token) {
    $call('POST', 'create', $bad, $token);
}, 422, 'API body byte limit');
$bad = $input;
$bad['news_description'] = '😀';
rejected(function () use ($call, $bad, $token) {
    $call('POST', 'create', $bad, $token);
}, 422, 'unsupported utf8mb4 never silently truncated');
check(count($store->rows('SELECT * FROM tbl_news')) === 1, 'rejected creates made no rows');
check($call('POST', 'delete', ['id' => $id], $token)['ok'], 'delete');
check($call('GET', '', ['nid' => $id]) === [], 'deletion visible to legacy-shaped readers');
check($call('POST', 'delete', ['id' => $id], $token)['ok'], 'delete idempotent');
$second = $call('POST', 'login', ['username' => 'fixture-admin', 'password' => 'fixture-password'])['access_token'];
check($second !== $token, 'separate login tokens');
check($call('POST', 'logout', [], $token)['ok'], 'logout revokes this token');
rejected(function () use ($call, $token) {
    $call('GET', 'me', [], $token);
}, 401, 'revoked token rejected');
check($call('GET', 'me', [], $second)['ok'], 'logout leaves other clients logged in');
$store->exec("UPDATE tbl_user SET Password = 'changed-password-hash'");
rejected(function () use ($call, $second) {
    $call('GET', 'me', [], $second);
}, 401, 'panel password change invalidates tokens');
for ($i = 0; $i < 9; $i++) {
    rejected(function () use ($call) {
        $call('POST', 'login', ['username' => 'missing', 'password' => 'wrong']);
    }, 401, 'failed attempt '.$i);
}
rejected(function () use ($call) {
    $call('POST', 'login', ['username' => 'missing', 'password' => 'wrong']);
}, 429, 'IP rate limited');
rejected(function () use ($call) {
    $call('POST', 'login', ['username' => 'missing', 'password' => 'wrong'], null, 2000000901);
}, 401, 'rate window expires');
echo 'PASS: '.$checks." backend checks (production SQL on isolated MySQL/MariaDB test database).\n";
