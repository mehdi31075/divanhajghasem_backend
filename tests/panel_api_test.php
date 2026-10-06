<?php

require __DIR__.'/fixture_store.php';

class FixtureImages
{
    public $calls = 0;

    public function save($file)
    {
        $this->calls++;
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new DivanApiError(422, 'invalid_image', 'Invalid image');
        }

        return 'fixture-'.$this->calls.'.png';
    }
}
$store = new FixtureStore;
$images = new FixtureImages;
$api = new DivanMobileApi($store, 3600, $images);
$checks = 0;
function check($ok, $label)
{
    global $checks;
    if (! $ok) {
        throw new RuntimeException('FAILED: '.$label);
    } $checks++;
}
function rejected($fn, $status, $label)
{
    try {
        $fn();
    } catch (DivanApiError $e) {
        check($e->status === $status, $label);

        return;
    } throw new RuntimeException('FAILED: '.$label);
}
$call = function ($method, $action, $data = [], $token = null, $files = []) use ($api) {
    return $api->handle($method, $action, $data, $token, 'fixture-ip', 2000000000, $files);
};
$token = $call('POST', 'login', ['username' => 'fixture-admin', 'password' => 'fixture-password'])['access_token'];
foreach (['stats', 'posts', 'account'] as $action) {
    rejected(function () use ($call, $action) {
        $call('GET', $action);
    }, 401, 'anonymous '.$action);
}
foreach (['category_create', 'category_update', 'category_delete', 'account_update'] as $action) {
    rejected(function () use ($call, $action) {
        $call('POST', $action);
    }, 401, 'anonymous '.$action);
}
$cat = ['category_name' => 'دسته تازه', 'author' => 'نویسنده'];
rejected(function () use ($call, $cat, $token) {
    $call('POST', 'category_create', $cat, $token);
}, 422, 'image required on create');
$id = $call('POST', 'category_create', $cat, $token, ['category_image' => ['error' => UPLOAD_ERR_OK]])['cid'];
check($store->categoryRecord($id)['category_image'] === 'fixture-1.png', 'new image saved');
check(count($call('GET', '')['AndroidEbookApp']) === 3, 'new category visible anonymously');
$cat['id'] = $id;
$cat['category_name'] = 'نام تازه';
$call('POST', 'category_update', $cat, $token, ['category_image' => ['error' => UPLOAD_ERR_NO_FILE]]);
check($images->calls === 1 && $store->categoryRecord($id)['category_image'] === 'fixture-1.png', 'no upload preserves current image');
$call('POST', 'category_update', $cat, $token, ['category_image' => ['error' => UPLOAD_ERR_OK]]);
check($store->categoryRecord($id)['category_image'] === 'fixture-2.png', 'replacement image');
$bad = $cat;
$bad['author'] = str_repeat('آ', 51);
rejected(function () use ($call, $bad, $token) {
    $call('POST', 'category_update', $bad, $token);
}, 422, 'author schema limit');
check($images->calls === 2, 'invalid data does not upload');
$article = ['news_heading' => 'نام شعر', 'news_date' => 'زیرعنوان', 'cid' => $id, 'news_description' => '<p>متن</p>'];
$nid = $call('POST', 'create', $article, $token)['nid'];
rejected(function () use ($call, $id, $token) {
    $call('POST', 'category_delete', ['id' => $id], $token);
}, 409, 'populated category cannot disappear');
check($store->article($nid) !== null && $store->category($id), 'category deletion protects public content');
for ($i = 0; $i < 51; $i++) {
    $call('POST', 'create', $article, $token);
}
$first = $call('GET', 'posts', ['page' => '1'], $token);
$next = $call('GET', 'posts', ['page' => '2'], $token);
check(count($first['posts']) === 50 && count($next['posts']) === 2 && $first['total'] === 52, 'pagination');
check(count(array_intersect(array_column($first['posts'], 'nid'), array_column($next['posts'], 'nid'))) === 0, 'distinct pages');
check($call('GET', 'posts', ['category_id' => '62'], $token)['total'] === 0, 'category filter');
check($call('GET', 'posts', ['q' => 'نام شعر'], $token)['total'] === 52, 'Persian search');
check($call('GET', 'stats', [], $token)['stats']['posts'] == 52, 'real counts');
foreach ($call('GET', '', ['cat_id' => $id])['AndroidEbookApp'] as $row) {
    $call('POST', 'delete', ['id' => $row['nid']], $token);
}
$call('POST', 'category_delete', ['id' => $id], $token);
check(! $store->category($id), 'empty category deletion');
$account = $call('GET', 'account', [], $token)['account'];
check(! array_key_exists('Password', $account), 'never disclose hash in account response');
$call('POST', 'account_update', ['email' => 'fixture@example.test'], $token);
check($call('GET', 'account', [], $token)['account']['Email'] === 'fixture@example.test', 'email updated');
check($call('GET', 'me', [], $token)['ok'], 'email keeps tokens valid');
$change = ['email' => 'new@example.test', 'old_password' => 'wrong', 'new_password' => 'new-fixture-password', 'confirm_password' => 'new-fixture-password'];
rejected(function () use ($call, $change, $token) {
    $call('POST', 'account_update', $change, $token);
}, 422, 'old password mandatory');
check($store->account('fixture-admin')['Email'] === 'fixture@example.test', 'rejected password leaves account unchanged');
$change['old_password'] = 'fixture-password';
$change['confirm_password'] = 'mismatch';
rejected(function () use ($call, $change, $token) {
    $call('POST', 'account_update', $change, $token);
}, 422, 'confirmation required');
$change['confirm_password'] = 'new-fixture-password';
check($call('POST', 'account_update', $change, $token)['reauthenticate'], 'password update requires login again');
rejected(function () use ($call, $token) {
    $call('GET', 'me', [], $token);
}, 401, 'password update expires existing token');
check($call('POST', 'login', ['username' => 'fixture-admin', 'password' => 'new-fixture-password'])['ok'], 'same account new password');
echo 'PASS: '.$checks." panel API checks.\n";
