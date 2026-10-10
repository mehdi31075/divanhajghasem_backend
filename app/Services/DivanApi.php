<?php

namespace App\Services;

use App\Exceptions\ApiError;
use RuntimeException;

class DivanApi
{
    private $store;

    private $ttl;

    private $images;

    private $media;

    public function __construct($store, $ttl = 86400, $images = null, $media = null)
    {
        $this->store = $store;
        $this->images = $images;
        $this->media = $media;
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

    private function supportPrincipal($bearer, $now)
    {
        if (! is_string($bearer) || ! preg_match('/^[a-f0-9]{64}$/', $bearer)) {
            throw new ApiError(401, 'support_login_required', 'برای استفاده از پشتیبانی وارد حساب شوید.');
        }
        $token = $this->store->supportToken(hash('sha256', $bearer));
        $user = $token ? $this->store->supportUser($token['user_id']) : null;
        if (! $token || (int) $token['expires_at'] <= $now || ! $user) {
            throw new ApiError(401, 'support_login_required', 'ورود منقضی شده است؛ دوباره وارد شوید.');
        }

        return $user;
    }

    private function supportMobile($value)
    {
        if (! is_string($value)) {
            throw new ApiError(422, 'invalid_support_mobile', 'شمارهٔ موبایل معتبر وارد کنید.');
        }
        $value = strtr(trim($value), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $value = preg_replace('/[\s()\-]/u', '', $value);
        if (preg_match('/^09[0-9]{9}$/', $value)) {
            return '+98'.substr($value, 1);
        }
        if (preg_match('/^\+989[0-9]{9}$/', $value)) {
            return $value;
        }
        throw new ApiError(422, 'invalid_support_mobile', 'شمارهٔ موبایل باید مانند ۰۹۱۲۳۴۵۶۷۸۹ باشد.');
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
        if (! in_array($action, ['login', 'me', 'logout', 'create', 'update', 'delete', 'stats', 'posts', 'pages', 'page_update', 'account', 'account_update', 'category_create', 'category_update', 'category_delete', 'media_list', 'media_upload', 'media_update', 'media_delete', 'support_start', 'support_verify', 'support_send', 'support_mine', 'support_logout', 'support_list', 'support_reply', 'support_reply_update', 'support_reply_delete', 'support_create', 'support_check', 'user_start', 'user_verify', 'user_me', 'user_logout', 'users', 'article_view', 'app_info', 'app_releases', 'app_upload', 'app_set_latest', 'app_delete'], true)) {
            throw new ApiError(404, 'unknown_action', 'این عملیات وجود ندارد.');
        }
        $requiredMethod = in_array($action, ['me', 'stats', 'posts', 'pages', 'account', 'media_list', 'support_list', 'support_mine', 'user_me', 'users', 'app_info', 'app_releases'], true) ? 'GET' : 'POST';
        if ($method !== $requiredMethod) {
            throw new ApiError(405, 'method_not_allowed', 'روش درخواست معتبر نیست.');
        }
        if ($action === 'article_view') {
            $id = $this->id($input['nid'] ?? null);
            if (! $this->store->article($id)) {
                throw new ApiError(404, 'article_not_found', 'مطلب پیدا نشد.');
            }

            return ['ok' => true, 'nid' => $id, 'views' => $this->store->incrementArticleViews($id)];
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
        if ($action === 'support_start' || $action === 'user_start') {
            $name = $input['name'] ?? null;
            if ($name !== null && (! is_string($name) || trim($name) === '' || ! preg_match('//u', $name) || mb_strlen(trim($name)) > 120)) {
                throw new ApiError(422, 'invalid_support_name', 'نام را کامل و حداکثر در ۱۲۰ نویسه وارد کنید.');
            }
            $mobile = $this->supportMobile($input['mobile'] ?? null);
            $ipHash = hash('sha256', (string) $ip);
            $this->store->deleteOldSupportOtps($now - 3600);
            if ($this->store->supportOtpCountFromIp($ipHash, $now - 3600) >= 10) {
                throw new ApiError(429, 'support_otp_rate_limited', 'تعداد درخواست کد زیاد است؛ کمی بعد دوباره تلاش کنید.');
            }
            $existing = $this->store->supportUserByPhone($mobile);
            $user = $existing;
            // Retain the previous support flow for already shipped clients that
            // still submit the name before requesting their first OTP.
            if (! $user && is_string($name) && trim($name) !== '') {
                $user = $this->store->saveSupportUser(trim($name), $mobile, $now);
            }
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $challenge = $this->randomToken();
            $this->store->createSupportOtp($challenge, $user['id'] ?? null, $mobile, hash('sha256', $code), $ipHash, $now, $now + 300);

            return ['ok' => true, 'challenge_id' => $challenge, 'test_otp' => $code, 'mode' => $existing ? 'login' : 'register', 'expires_in' => 300];
        }
        if ($action === 'support_verify' || $action === 'user_verify') {
            $challenge = $input['challenge_id'] ?? null;
            $code = $input['otp'] ?? null;
            if (! is_string($challenge) || ! preg_match('/^[a-f0-9]{64}$/', $challenge) || ! is_string($code)) {
                throw new ApiError(422, 'invalid_support_otp', 'کد ورود معتبر نیست.');
            }
            $code = strtr(trim($code), [
                '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            ]);
            if (! preg_match('/^[0-9]{6}$/', $code)) {
                throw new ApiError(422, 'invalid_support_otp', 'کد ورود باید ۶ رقم باشد.');
            }
            $otp = $this->store->supportOtp($challenge);
            if (! $otp || $otp['consumed_at'] !== null || (int) $otp['expires_at'] <= $now || (int) $otp['attempts'] >= 5) {
                throw new ApiError(401, 'support_otp_expired', 'کد ورود منقضی شده است؛ کد تازه بگیرید.');
            }
            $this->store->incrementSupportOtpAttempts($challenge);
            if (! hash_equals($otp['code_hash'], hash('sha256', $code))) {
                throw new ApiError(401, 'invalid_support_otp', 'کد ورود درست نیست.');
            }
            $userId = $otp['user_id'] ?? null;
            if (! $userId) {
                $name = $input['name'] ?? null;
                if ($name === null) {
                    return ['ok' => true, 'needs_name' => true, 'mode' => 'register'];
                }
                if (! is_string($name) || trim($name) === '' || ! preg_match('//u', $name) || mb_strlen(trim($name)) > 120) {
                    throw new ApiError(422, 'invalid_support_name', 'نام را کامل و حداکثر در ۱۲۰ نویسه وارد کنید.');
                }
                $user = $this->store->saveSupportUser(trim($name), $otp['mobile'], $now);
                $userId = $user['id'];
            }
            $this->store->consumeSupportOtp($challenge, $now);
            $token = $this->randomToken();
            $this->store->issueSupportToken(hash('sha256', $token), $userId, $now, $now + 2592000);
            $user = $this->store->supportUser($userId);

            return ['ok' => true, 'access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 2592000, 'user' => $user];
        }
        if (in_array($action, ['support_send', 'support_mine', 'support_logout', 'user_me', 'user_logout'], true)) {
            $user = $this->supportPrincipal($bearer, $now);
            if ($action === 'user_me') {
                return ['ok' => true, 'user' => $user];
            }
            if ($action === 'support_mine') {
                return ['ok' => true, 'messages' => array_map([$this, 'formatSupportMessage'], $this->store->supportMessagesForUser($user['id']))];
            }
            if ($action === 'support_logout' || $action === 'user_logout') {
                $this->store->revokeSupportToken(hash('sha256', $bearer));

                return ['ok' => true];
            }
            $message = $input['message'] ?? null;
            if (! is_string($message) || trim($message) === '' || mb_strlen($message) > 3000 || ! preg_match('//u', $message)) {
                throw new ApiError(422, 'invalid_support_message', 'پیام باید بین ۱ تا ۳۰۰۰ نویسه باشد.');
            }
            $ticketId = $input['ticket_id'] ?? $input['id'] ?? null;
            if ($ticketId !== null) {
                $tid = $this->id($ticketId);
                $ticket = $this->store->supportMessageById($tid);
                if (! $ticket || (string) ($ticket['user_id'] ?? '') !== (string) $user['id']) {
                    throw new ApiError(404, 'support_message_not_found', 'درخواست پشتیبانی پیدا نشد.');
                }
                if ($this->store->supportMessageCountForUser($user['id'], $now - 3600) >= 20) {
                    throw new ApiError(429, 'support_rate_limited', 'تعداد پیام‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.');
                }
                $this->store->createSupportReply($tid, 'user', trim($message), $now);

                return ['ok' => true, 'created_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now)];
            }
            if ($this->store->supportMessageCountForUser($user['id'], $now - 3600) >= 10) {
                throw new ApiError(429, 'support_rate_limited', 'تعداد پیام‌ها زیاد است؛ یک ساعت دیگر دوباره تلاش کنید.');
            }
            $this->store->createSupportMessage($user['id'], hash('sha256', (string) $ip), trim($message), $now);

            return ['ok' => true, 'created_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now)];
        }
        if ($action === 'support_create' || $action === 'support_check') {
            throw new ApiError(410, 'support_receipts_retired', 'برای پیام پشتیبانی وارد حساب خود شوید.');
        }
        $username = $this->principal($bearer, $now);
        if ($action === 'media_list') {
            if (! $this->media) {
                throw new ApiError(503, 'media_unavailable', 'کتابخانهٔ رسانه در دسترس نیست.');
            }

            $items = $this->media->all();

            return ['ok' => true, 'media' => $items, 'videos' => array_values(array_filter($items, static fn ($item) => $item['type'] === 'video'))];
        }
        if ($action === 'media_upload') {
            if (! $this->media) {
                throw new ApiError(503, 'media_unavailable', 'بارگذاری رسانه در دسترس نیست.');
            }
            $type = $input['media_type'] ?? null;
            if (! is_string($type)) {
                throw new ApiError(422, 'invalid_media_type', 'نوع فایل پشتیبانی نمی‌شود.');
            }

            return ['ok' => true, 'media' => $this->media->upload($files['media_file'] ?? null, $type, $now)];
        }
        if ($action === 'media_update') {
            if (! $this->media) throw new ApiError(503, 'media_unavailable', 'کتابخانهٔ رسانه در دسترس نیست.');
            return ['ok' => true, 'media' => $this->media->rename($input['id'] ?? null, $input['name'] ?? null)];
        }
        if ($action === 'media_delete') {
            if (! $this->media) throw new ApiError(503, 'media_unavailable', 'کتابخانهٔ رسانه در دسترس نیست.');
            $this->media->delete($input['id'] ?? null);

            return ['ok' => true];
        }
        if ($action === 'support_list') {
            return ['ok' => true, 'messages' => array_map([$this, 'formatSupportMessage'], $this->store->supportMessages())];
        }
        if ($action === 'users') {
            return ['ok' => true, 'users' => array_map(static function ($user) {
                $user['id'] = (string) $user['id'];
                $user['support_messages'] = (string) $user['support_messages'];
                $user['created_at'] = gmdate('Y-m-d\\TH:i:s\\Z', (int) $user['created_at']);
                return $user;
            }, $this->store->supportUsers())];
        }
        if ($action === 'app_info' || $action === 'app_releases') {
            return ['ok' => true, 'releases' => $this->appReleases(), 'latest' => $this->latestRelease()];
        }
        if ($action === 'app_upload') {
            $apk = $files['app_apk'] ?? null;
            $version = $input['version'] ?? null;
            $changelog = $input['changelog'] ?? null;
            $isLatest = isset($input['is_latest']) ? filter_var($input['is_latest'], FILTER_VALIDATE_BOOLEAN) : true;
            return ['ok' => true, 'releases' => $this->uploadAppRelease($apk, $version, $changelog, $isLatest, $now), 'latest' => $this->latestRelease()];
        }
        if ($action === 'app_set_latest') {
            $id = (int) ($input['id'] ?? 0);
            return ['ok' => true, 'releases' => $this->setLatestAppRelease($id), 'latest' => $this->latestRelease()];
        }
        if ($action === 'app_delete') {
            $id = (int) ($input['id'] ?? 0);
            return ['ok' => true, 'releases' => $this->deleteAppRelease($id), 'latest' => $this->latestRelease()];
        }
        if ($action === 'support_reply') {
            $id = $this->id($input['id'] ?? null);
            $reply = $input['reply'] ?? null;
            if (! is_string($reply) || mb_strlen($reply) > 4000 || ! preg_match('//u', $reply)) {
                throw new ApiError(422, 'invalid_support_reply', 'پاسخ باید حداکثر ۴۰۰۰ نویسه باشد.');
            }
            $reply = trim($reply);
            if (! $this->store->replyToSupport($id, $reply === '' ? null : $reply, $reply === '' ? null : $now) && ! $this->store->supportExists($id)) {
                throw new ApiError(404, 'support_message_not_found', 'پیام پشتیبانی پیدا نشد.');
            }

            return ['ok' => true];
        }
        if ($action === 'support_reply_update') {
            $id = $input['id'] ?? null;
            $ticketId = $input['ticket_id'] ?? null;
            $message = $input['message'] ?? $input['reply'] ?? null;
            if (! is_string($message) || trim($message) === '' || mb_strlen($message) > 4000 || ! preg_match('//u', $message)) {
                throw new ApiError(422, 'invalid_support_reply', 'متن پیام باید بین ۱ تا ۴۰۰۰ نویسه باشد.');
            }
            if ($id === 'legacy' && $ticketId) {
                $tid = $this->id($ticketId);
                $this->store->replyToSupport($tid, trim($message), $now);

                return ['ok' => true];
            }
            $cleanId = $this->id($id);
            $existing = $this->store->supportReplyById($cleanId);
            if (! $existing || ($existing['sender'] ?? '') !== 'admin') {
                throw new ApiError(404, 'reply_not_found', 'پیام پشتیبانی برای ویرایش پیدا نشد.');
            }
            $this->store->updateSupportReply($cleanId, trim($message));

            return ['ok' => true];
        }
        if ($action === 'support_reply_delete') {
            $id = $input['id'] ?? null;
            $ticketId = $input['ticket_id'] ?? null;
            if ($id === 'legacy' && $ticketId) {
                $tid = $this->id($ticketId);
                $this->store->replyToSupport($tid, null, null);

                return ['ok' => true];
            }
            $cleanId = $this->id($id);
            $existing = $this->store->supportReplyById($cleanId);
            if (! $existing || ($existing['sender'] ?? '') !== 'admin') {
                throw new ApiError(404, 'reply_not_found', 'پیام پشتیبانی برای حذف پیدا نشد.');
            }
            $this->store->deleteSupportReply($cleanId);

            return ['ok' => true];
        }
        if ($action === 'pages') {
            return ['ok' => true, 'pages' => $this->store->pages()];
        }
        if ($action === 'page_update') {
            $slug = $input['slug'] ?? null;
            if (! in_array($slug, ['first-talk', 'last-talk', 'contact'], true)) {
                throw new ApiError(422, 'invalid_page', 'صفحه معتبر نیست.');
            }
            foreach (['title' => 1000, 'html_body' => 3000000] as $field => $limit) {
                if (! isset($input[$field]) || ! is_string($input[$field]) || trim($input[$field]) === '' || strlen($input[$field]) > $limit || ! preg_match('//u', $input[$field]) || preg_match('/[\xF0-\xF4][\x80-\xBF]{3}/', $input[$field])) {
                    throw new ApiError(422, 'invalid_page_content', 'عنوان و متن معتبر و در محدودهٔ مجاز وارد کنید؛ ایموجی در دیتابیس فعلی پشتیبانی نمی‌شود.');
                }
            }
            if (mb_strlen($input['title']) > 255) {
                throw new ApiError(422, 'invalid_page_content', 'عنوان حداکثر ۲۵۵ نویسه باشد.');
            }
            $revision = $this->id($input['revision'] ?? null);
            if (! $this->store->updatePage($slug, $input['title'], $input['html_body'], $revision, $now)) {
                throw new ApiError(409, 'page_changed', 'صفحه روی سرور تغییر کرده است؛ متن خود را نگه دارید و نسخهٔ تازه را بررسی کنید.');
            }

            return ['ok' => true];
        }
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
        if (preg_match_all('/./us', $input['news_heading'], $unused) > 500 || preg_match_all('/./us', $input['news_date'], $unused) > 255 || strlen($input['news_description']) > 5000000) {
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
            $this->store->updateArticle($id, $input, $now);
        } else {
            $id = $this->store->createArticle($input, $now);
        }

        return ['ok' => true, 'nid' => $id];
    }

    private function formatSupportMessage($row)
    {
        $row['id'] = (string) $row['id'];
        if (array_key_exists('user_id', $row)) {
            unset($row['user_id']);
        }
        $row['created_at'] = is_numeric($row['created_at'])
            ? gmdate('Y-m-d\\TH:i:s\\Z', (int) $row['created_at'])
            : (string) $row['created_at'];
        $row['replied_at'] = $row['replied_at'] === null
            ? null
            : (is_numeric($row['replied_at']) ? gmdate('Y-m-d\\TH:i:s\\Z', (int) $row['replied_at']) : (string) $row['replied_at']);

        $replies = [];
        if (! empty($row['replies']) && is_array($row['replies'])) {
            foreach ($row['replies'] as $reply) {
                $replies[] = [
                    'id' => (string) ($reply['id'] ?? ''),
                    'ticket_id' => (string) ($reply['ticket_id'] ?? $row['id']),
                    'sender' => $reply['sender'] ?? 'admin',
                    'message' => $reply['message'] ?? '',
                    'created_at' => is_numeric($reply['created_at'] ?? null)
                        ? gmdate('Y-m-d\\TH:i:s\\Z', (int) $reply['created_at'])
                        : (string) ($reply['created_at'] ?? ''),
                ];
            }
        } elseif ($row['reply'] !== null && trim((string) $row['reply']) !== '') {
            $replies[] = [
                'id' => 'legacy',
                'ticket_id' => (string) $row['id'],
                'sender' => 'admin',
                'message' => (string) $row['reply'],
                'created_at' => $row['replied_at'] ?? $row['created_at'],
            ];
        }
        $row['replies'] = $replies;

        return $row;
    }

    public function appCandidates(): array
    {
        return [
            storage_path('app/apk/divan-ansaralhossein.apk'),
            public_path('download/divan-ansaralhossein.apk'),
            base_path('../divan-ansaralhossein.apk'),
            base_path('../build/app/outputs/flutter-apk/app-release.apk'),
        ];
    }

    public function appReleases(): array
    {
        return array_map([$this, 'formatRelease'], $this->store->appReleases());
    }

    public function latestRelease(): ?array
    {
        $row = $this->store->latestAppRelease();

        return $row ? $this->formatRelease($row) : null;
    }

    public function releaseById(int $id): ?array
    {
        $row = $this->store->appReleaseById($id);

        return $row ? $this->formatRelease($row) : null;
    }

    public function releaseByVersion(string $version): ?array
    {
        $row = $this->store->appReleaseByVersion($version);

        return $row ? $this->formatRelease($row) : null;
    }

    public function setLatestAppRelease(int $id): array
    {
        $this->store->setLatestAppRelease($id);

        return $this->appReleases();
    }

    public function deleteAppRelease(int $id): array
    {
        $this->store->deleteAppRelease($id);

        return $this->appReleases();
    }

    public function uploadAppRelease($file, ?string $version, ?string $changelog, bool $isLatest, int $now): array
    {
        if (! $file || ! is_object($file) || ! method_exists($file, 'isValid') || ! $file->isValid()) {
            throw new ApiError(422, 'invalid_app_file', 'فایل بارگذاری‌شده معتبر نیست.');
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext !== 'apk') {
            throw new ApiError(422, 'invalid_file_type', 'فقط فایل با پسوند apk. مجاز است.');
        }

        $cleanVersion = trim((string) $version);
        $cleanVersion = $this->toEnglishDigits($cleanVersion);
        if ($cleanVersion === '') {
            $count = count($this->store->appReleases());
            $cleanVersion = '1.0.'.($count + 1);
        }
        $cleanVersion = preg_replace('/[^0-9a-zA-Z._-]/', '', $cleanVersion);
        if ($cleanVersion === '') {
            $cleanVersion = '1.0.0';
        }

        $filename = 'divan-ansaralhossein-v'.$cleanVersion.'.apk';
        $destDir = storage_path('app/apk');
        if (! is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        $dest = $destDir.DIRECTORY_SEPARATOR.$filename;
        $file->move($destDir, $filename);

        if ($isLatest) {
            $stdDest = $destDir.DIRECTORY_SEPARATOR.'divan-ansaralhossein.apk';
            @copy($dest, $stdDest);
            $publicDownloadDir = public_path('download');
            if (is_dir($publicDownloadDir)) {
                @copy($dest, $publicDownloadDir.DIRECTORY_SEPARATOR.'divan-ansaralhossein.apk');
            }
        }

        $this->store->createAppRelease([
            'version' => $cleanVersion,
            'build_number' => 1,
            'filename' => $filename,
            'file_path' => $dest,
            'size_bytes' => filesize($dest),
            'changelog' => trim((string) $changelog),
            'is_latest' => $isLatest ? 1 : 0,
            'created_at' => $now,
        ]);

        return $this->appReleases();
    }

    public function formatRelease(array $row): array
    {
        $id = (string) $row['id'];
        $version = (string) $row['version'];
        $sizeBytes = (int) ($row['size_bytes'] ?? 0);
        $sizeHuman = $sizeBytes > 0 ? (round($sizeBytes / (1024 * 1024), 1).' مگابایت') : '—';
        $mtime = (int) ($row['created_at'] ?? 0);
        $filename = $row['filename'] ?? 'divan-ansaralhossein.apk';

        return [
            'id' => $id,
            'version' => $this->toPersianDigits($version),
            'version_raw' => $version,
            'build_number' => (int) ($row['build_number'] ?? 1),
            'filename' => $filename,
            'file_path' => $row['file_path'] ?? '',
            'download_url' => url('/download/app?id='.$id),
            'size_bytes' => $sizeBytes,
            'size_human' => $sizeHuman,
            'changelog' => $row['changelog'] ?? '',
            'is_latest' => (bool) ($row['is_latest'] ?? false),
            'created_at' => $mtime ? gmdate('Y-m-d\\TH:i:s\\Z', $mtime) : null,
        ];
    }

    public function toPersianDigits(string $str): string
    {
        $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

        return str_replace($en, $fa, $str);
    }

    public function toEnglishDigits(string $str): string
    {
        $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        return str_replace($fa, $en, $str);
    }
}
