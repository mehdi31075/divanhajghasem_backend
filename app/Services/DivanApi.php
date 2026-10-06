<?php

namespace App\Services;

use App\Exceptions\ApiError;
use RuntimeException;

class DivanApi
{
    private $store;

    private $ttl;

    private $images;

    public function __construct($store, $ttl = 86400, $images = null)
    {
        $this->store = $store;
        $this->images = $images;
        $this->ttl = max(60, min((int) $ttl, 604800));
    }

    private function id($value)
    {
        if (! is_scalar($value) || ! preg_match('/^[1-9][0-9]{0,9}$/', (string) $value)) {
            throw new ApiError(422, 'invalid_id', 'شناسه معتبر نیست.');
        }

        return (string) $value;
    }

    private function credentials($input)
    {
        if (! isset($input['username'], $input['password']) ||
            ! is_string($input['username']) || ! is_string($input['password']) ||
            trim($input['username']) === '' || $input['password'] === '' ||
            strlen($input['username']) > 255 || strlen($input['password']) > 4096) {
            throw new ApiError(422, 'invalid_credentials', 'نام کاربری و رمز را وارد کنید.');
        }

        return [strtolower(trim($input['username'])), $input['password']];
    }

    public function principal($bearer, $now)
    {
        if (! is_string($bearer) || ! preg_match('/^[a-f0-9]{64}$/', $bearer)) {
            throw new ApiError(401, 'unauthorized', 'ابتدا وارد حساب شوید.');
        }
        $hash = hash('sha256', $bearer);
        $token = $this->store->token($hash);
        $user = $token ? $this->store->user($token['username']) : null;
        if (! $token || (int) $token['expires_at'] <= $now || ! $user ||
            ! hash_equals($token['password_digest'], hash('sha256', $user['Password']))) {
            throw new ApiError(401, 'unauthorized', 'ورود منقضی شده است.');
        }

        return $token['username'];
    }

    private function passwordMatches($user, $password)
    {
        $hash = $user['Password'];

        return preg_match('/^[a-f0-9]{64}$/', $hash)
            ? hash_equals($hash, hash('sha256', strtolower($user['Username']).$password))
            : password_verify($password, $hash);
    }

    private function randomToken()
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(32));
        }
        if (! function_exists('openssl_random_pseudo_bytes')) {
            throw new RuntimeException('Secure random source unavailable');
        }
        $strong = false;
        $bytes = openssl_random_pseudo_bytes(32, $strong);
        if (! $strong || $bytes === false || strlen($bytes) !== 32) {
            throw new RuntimeException('Secure random source unavailable');
        }

        return bin2hex($bytes);
    }

    public function handle($method, $action, $input, $bearer, $ip, $now = null, $files = [])
    {
        $now = $now === null ? time() : $now;
        if ($action === '') {
            if ($method !== 'GET') {
                throw new ApiError(405, 'method_not_allowed', 'این مسیر فقط برای خواندن است.');
            }
            foreach (['cat_id', 'nid'] as $key) {
                if (isset($input[$key])) {
                    $input[$key] = $this->id($input[$key]);
                }
            }
            if (isset($input['latest_news'])) {
                $input['latest_news'] = min(500, (int) $this->id($input['latest_news']));
            }
            $rows = $this->store->articles($input);
            // Mirror api.php including [] for no rows and string-valued fields.
            foreach ($rows as &$row) {
                foreach ($row as &$value) {
                    if ($value !== null) {
                        $value = (string) $value;
                    }
                }
            }

            return $rows ? ['AndroidEbookApp' => $rows] : [];
        }
        if (! in_array($action, ['login', 'me', 'logout', 'create', 'update', 'delete', 'stats', 'posts', 'account', 'account_update', 'category_create', 'category_update', 'category_delete'], true)) {
            throw new ApiError(404, 'unknown_action', 'این عملیات وجود ندارد.');
        }
        $requiredMethod = in_array($action, ['me', 'stats', 'posts', 'account'], true) ? 'GET' : 'POST';
        if ($method !== $requiredMethod) {
            throw new ApiError(405, 'method_not_allowed', 'روش درخواست معتبر نیست.');
        }
        if ($action === 'login') {
            [$username, $password] = $this->credentials($input);
            $ipHash = hash('sha256', $ip);
            if ($this->store->failedLogins($ipHash, $now - 900) >= 10) {
                throw new ApiError(429, 'rate_limited', 'تعداد تلاش‌های ورود زیاد است؛ ۱۵ دقیقه بعد تلاش کنید.');
            }
            $user = $this->store->user($username);
            // Existing SHA-256 accounts remain valid; newly changed passwords use bcrypt.
            if (! $user || ! $this->passwordMatches($user, $password)) {
                $this->store->failLogin($ipHash, $now);
                throw new ApiError(401, 'invalid_credentials', 'نام کاربری یا رمز پذیرفته نشد.');
            }
            $token = $this->randomToken();
            $this->store->issueToken(hash('sha256', $token), $user['Username'], hash('sha256', $user['Password']), $now, $now + $this->ttl);

            return ['ok' => true, 'access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $this->ttl, 'username' => $user['Username']];
        }
        $username = $this->principal($bearer, $now);
        if ($action === 'me') {
            return ['ok' => true, 'username' => $username];
        }
        if ($action === 'logout') {
            $this->store->revokeToken(hash('sha256', $bearer));

            return ['ok' => true];
        }
        if ($action === 'stats') {
            return ['ok' => true, 'stats' => $this->store->stats()];
        }
        if ($action === 'posts') {
            $page = isset($input['page']) ? (int) $this->id($input['page']) : 1;
            if ($page > 1000000) {
                throw new ApiError(422, 'invalid_page', 'شماره صفحه معتبر نیست.');
            }
            $search = isset($input['q']) ? $input['q'] : '';
            if (! is_string($search) || strlen($search) > 400) {
                throw new ApiError(422, 'invalid_search', 'جستجو معتبر نیست.');
            }
            $category = isset($input['category_id']) ? $this->id($input['category_id']) : null;

            return array_merge(['ok' => true], $this->store->posts($page, $search, $category));
        }
        if ($action === 'account') {
            return ['ok' => true, 'account' => $this->store->account($username)];
        }
        if ($action === 'account_update') {
            $email = isset($input['email']) ? $input['email'] : null;
            if (! is_string($email) || strlen($email) > 100 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new ApiError(422, 'invalid_email', 'ایمیل معتبر وارد کنید.');
            }
            $passwordHash = null;
            $changePassword = isset($input['new_password']) && $input['new_password'] !== '';
            if ($changePassword) {
                foreach (['old_password', 'new_password', 'confirm_password'] as $key) {
                    if (! isset($input[$key]) || ! is_string($input[$key]) || strlen($input[$key]) > 4096) {
                        throw new ApiError(422, 'invalid_password', 'رمزهای ورود را کامل کنید.');
                    }
                }
                $user = $this->store->user($username);
                if (! $this->passwordMatches($user, $input['old_password'])) {
                    throw new ApiError(422, 'invalid_password', 'رمز فعلی درست نیست.');
                }
                if ($input['new_password'] !== $input['confirm_password']) {
                    throw new ApiError(422, 'password_mismatch', 'تکرار رمز مطابقت ندارد.');
                }
                if (strlen($input['new_password']) < 8) {
                    throw new ApiError(422, 'invalid_password', 'رمز جدید حداقل ۸ نویسه باشد.');
                }
                $passwordHash = password_hash($input['new_password'], PASSWORD_BCRYPT);
            }
            $this->store->updateAccount($username, $email, $passwordHash);

            return ['ok' => true, 'reauthenticate' => $changePassword];
        }
        if ($action === 'category_delete') {
            $id = $this->id(isset($input['id']) ? $input['id'] : null);
            $affected = $this->store->deleteCategory($id);
            if (! $affected && $this->store->category($id)) {
                throw new ApiError(409, 'category_not_empty', 'دسته دارای مطلب است؛ ابتدا مطالب را منتقل یا حذف کنید.');
            }

            return ['ok' => true, 'cid' => $id];
        }
        if ($action === 'category_create' || $action === 'category_update') {
            foreach (['category_name' => 255, 'author' => 50] as $key => $limit) {
                if (! isset($input[$key]) || ! is_string($input[$key]) || trim($input[$key]) === '' || ! preg_match('//u', $input[$key]) || preg_match_all('/./us', $input[$key], $unused) > $limit || preg_match('/[\xF0-\xF4][\x80-\xBF]{3}/', $input[$key])) {
                    throw new ApiError(422, 'invalid_category', 'نام دسته و نویسنده را کامل و با طول مجاز وارد کنید.');
                }
            }
            $previous = null;
            if ($action === 'category_update') {
                $id = $this->id(isset($input['id']) ? $input['id'] : null);
                $previous = $this->store->categoryRecord($id);
                if (! $previous) {
                    throw new ApiError(404, 'category_not_found', 'دسته وجود ندارد.');
                }
            }
            $image = $previous ? $previous['category_image'] : '';
            if (isset($files['category_image']) && (! is_array($files['category_image']) || ($files['category_image']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_NO_FILE)) {
                if (! $this->images) {
                    throw new RuntimeException('Image storage unavailable');
                }
                $image = $this->images->save($files['category_image']);
            } elseif ($previous === null) {
                throw new ApiError(422, 'image_required', 'تصویر دسته را انتخاب کنید.');
            }
            if ($previous) {
                $this->store->updateCategory($id, $input, $image);
            } else {
                $id = $this->store->createCategory($input, $image);
            }

            return ['ok' => true, 'cid' => $id];
        }
        if ($action === 'delete') {
            $id = $this->id(isset($input['id']) ? $input['id'] : null);
            $this->store->deleteArticle($id);

            return ['ok' => true, 'nid' => $id];
        }
        foreach (['news_heading', 'news_date', 'news_description'] as $key) {
            if (! isset($input[$key]) || ! is_string($input[$key]) || trim($input[$key]) === '') {
                throw new ApiError(422, 'missing_field', 'عنوان، عنوان فرعی، دسته و متن را کامل کنید.');
            }
        }
        if (preg_match_all('/./us', $input['news_heading'], $unused) > 500 || preg_match_all('/./us', $input['news_date'], $unused) > 255 || strlen($input['news_description']) > 65535) {
            throw new ApiError(422, 'field_too_long', 'طول متن بیش از حد مجاز است.');
        }
        foreach (['news_heading', 'news_date', 'news_description'] as $key) {
            if (! preg_match('//u', $input[$key]) || preg_match('/[\xF0-\xF4][\x80-\xBF]{3}/', $input[$key])) {
                throw new ApiError(422, 'unsupported_text', 'متن باید UTF-8 باشد؛ دیتابیس فعلی نویسه‌های چهار بایتی مثل ایموجی را پشتیبانی نمی‌کند.');
            }
        }
        $input['cid'] = $this->id(isset($input['cid']) ? $input['cid'] : null);
        if (! $this->store->category($input['cid'])) {
            throw new ApiError(422, 'category_not_found', 'دسته روی سرور وجود ندارد.');
        }
        if ($action === 'update') {
            $id = $this->id(isset($input['id']) ? $input['id'] : null);
            if (! $this->store->article($id)) {
                throw new ApiError(404, 'article_not_found', 'مطلب روی سرور وجود ندارد.');
            }
            $this->store->updateArticle($id, $input);
        } else {
            $id = $this->store->createArticle($input);
        }

        return ['ok' => true, 'nid' => $id];
    }
}
