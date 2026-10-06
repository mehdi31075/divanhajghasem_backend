<?php
// Additive API: PHP 5.6+ syntax; no sessions and no changes to legacy tables.
class DivanApiError extends Exception {
    public $status;
    public $errorCode;
    public function __construct($status, $code, $message) {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $code;
    }
}

class DivanMySqlStore {
    protected $db;
    public function __construct($db) { $this->db = $db; }

    // bind_result avoids requiring mysqlnd/get_result on old shared hosting.
    protected function query($sql, $params = array()) {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) throw new RuntimeException('Database prepare failed');
        if ($params) {
            $types = str_repeat('s', count($params));
            $arguments = array($types);
            foreach ($params as $key => $value) $arguments[] = &$params[$key];
            call_user_func_array(array($stmt, 'bind_param'), $arguments);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Database execution failed');
        }
        $metadata = $stmt->result_metadata();
        if (!$metadata) {
            $result = array('affected' => $stmt->affected_rows, 'id' => $stmt->insert_id);
            $stmt->close();
            return $result;
        }
        $row = array();
        $arguments = array();
        foreach ($metadata->fetch_fields() as $field) {
            $row[$field->name] = null;
            $arguments[] = &$row[$field->name];
        }
        call_user_func_array(array($stmt, 'bind_result'), $arguments);
        $rows = array();
        while ($stmt->fetch()) {
            $copy = array();
            foreach ($row as $key => $value) $copy[$key] = $value;
            $rows[] = $copy;
        }
        $stmt->close();
        return $rows;
    }
    public function user($username) {
        $rows = $this->query('SELECT Username, Password FROM tbl_user WHERE Username = ?', array($username));
        return count($rows) === 1 ? $rows[0] : null;
    }
    public function failedLogins($ip, $since) {
        $this->query('DELETE FROM divan_api_login_attempts WHERE attempted_at < ?', array($since));
        $rows = $this->query('SELECT COUNT(*) AS total FROM divan_api_login_attempts WHERE ip_hash = ? AND attempted_at >= ?', array($ip, $since));
        return (int)$rows[0]['total'];
    }
    public function failLogin($ip, $now) {
        $this->query('INSERT INTO divan_api_login_attempts (ip_hash, attempted_at) VALUES (?, ?)', array($ip, $now));
    }
    public function issueToken($hash, $username, $passwordDigest, $now, $expires) {
        $this->query('DELETE FROM divan_api_tokens WHERE expires_at <= ?', array($now));
        $this->query('INSERT INTO divan_api_tokens (token_hash, username, password_digest, created_at, expires_at) VALUES (?, ?, ?, ?, ?)', array($hash, $username, $passwordDigest, $now, $expires));
    }
    public function token($hash) {
        $rows = $this->query('SELECT username, password_digest, expires_at FROM divan_api_tokens WHERE token_hash = ?', array($hash));
        return count($rows) === 1 ? $rows[0] : null;
    }
    public function revokeToken($hash) {
        $this->query('DELETE FROM divan_api_tokens WHERE token_hash = ?', array($hash));
    }
    public function categoryRecord($id) {
        $rows = $this->query('SELECT cid, category_name, category_image, author, status FROM tbl_news_category WHERE cid = ?', array($id));
        return count($rows) === 1 ? $rows[0] : null;
    }
    public function createCategory($input, $image) {
        $result = $this->query('INSERT INTO tbl_news_category (category_name, category_image, author) VALUES (?, ?, ?)', array($input['category_name'], $image, $input['author']));
        return (string)$result['id'];
    }
    public function updateCategory($id, $input, $image) {
        $this->query('UPDATE tbl_news_category SET category_name = ?, author = ?, category_image = ? WHERE cid = ?', array($input['category_name'], $input['author'], $image, $id));
    }
    public function deleteCategory($id) {
        // Legacy MyISAM cannot atomically cascade. Only empty categories may be
        // deleted; move/delete their articles explicitly first.
        $result = $this->query('DELETE FROM tbl_news_category WHERE cid = ? AND NOT EXISTS (SELECT 1 FROM tbl_news WHERE cat_id = ?)', array($id, $id));
        return (int)$result['affected'];
    }
    public function stats() {
        $rows = $this->query('SELECT (SELECT COUNT(*) FROM tbl_news_category) AS categories, (SELECT COUNT(*) FROM tbl_news) AS posts');
        return $rows[0];
    }
    public function posts($page, $search, $category) {
        $where = array(); $params = array();
        if ($search !== '') { $where[] = '(news_heading LIKE ? OR news_description LIKE ?)'; $params[] = '%'.$search.'%'; $params[] = '%'.$search.'%'; }
        if ($category !== null) { $where[] = 'cat_id = ?'; $params[] = $category; }
        $filter = $where ? ' WHERE '.implode(' AND ', $where) : '';
        $total = $this->query('SELECT COUNT(*) AS total FROM tbl_news'.$filter, $params);
        $rows = $this->query('SELECT nid, news_heading, cat_id, news_date FROM tbl_news'.$filter.' ORDER BY nid DESC LIMIT 50 OFFSET '.(($page - 1) * 50), $params);
        return array('posts' => $rows, 'total' => (int)$total[0]['total'], 'page' => $page);
    }
    public function account($username) {
        $rows = $this->query('SELECT Username, Email FROM tbl_user WHERE Username = ?', array($username));
        return $rows[0];
    }
    public function updateAccount($username, $email, $passwordHash) {
        if ($passwordHash === null) $this->query('UPDATE tbl_user SET Email = ? WHERE Username = ?', array($email, $username));
        else $this->query('UPDATE tbl_user SET Email = ?, Password = ? WHERE Username = ?', array($email, $passwordHash, $username));
    }
    public function category($id) {
        $rows = $this->query('SELECT cid FROM tbl_news_category WHERE cid = ?', array($id));
        return count($rows) === 1;
    }
    public function article($id) {
        $rows = $this->articles(array('nid' => $id));
        return count($rows) === 1 ? $rows[0] : null;
    }
    public function articles($query) {
        // Explicit legacy columns: future unrelated schema additions do not leak.
        $sql = 'SELECT c.cid, c.category_name, c.category_image, c.author, c.status, n.nid, n.news_heading, n.cat_id, n.news_status, n.news_date, n.news_image, n.news_description FROM tbl_news_category c JOIN tbl_news n ON c.cid = n.cat_id';
        if (isset($query['cat_id'])) return $this->query($sql.' WHERE c.cid = ? ORDER BY n.nid ASC', array($query['cat_id']));
        if (isset($query['nid'])) return $this->query($sql.' WHERE n.nid = ?', array($query['nid']));
        if (isset($query['latest_news'])) return $this->query($sql.' ORDER BY n.nid ASC LIMIT '.(int)$query['latest_news']);
        return $this->query('SELECT cid, category_name, category_image, author, status FROM tbl_news_category ORDER BY cid DESC');
    }
    public function createArticle($input) {
        // INSERT...SELECT locks the category for this statement on MyISAM too.
        $result = $this->query("INSERT INTO tbl_news (news_heading, cat_id, news_date, news_description, news_image) SELECT ?, cid, ?, ?, '' FROM tbl_news_category WHERE cid = ?", array($input['news_heading'], $input['news_date'], $input['news_description'], $input['cid']));
        if (!(int)$result['affected']) throw new DivanApiError(409, 'category_changed', 'دسته پیش از ذخیره حذف شده است.');
        return (string)$result['id'];
    }
    public function updateArticle($id, $input) {
        $result = $this->query('UPDATE tbl_news SET news_heading = ?, cat_id = ?, news_date = ?, news_description = ? WHERE nid = ? AND EXISTS (SELECT 1 FROM tbl_news_category WHERE cid = ?)', array($input['news_heading'], $input['cid'], $input['news_date'], $input['news_description'], $id, $input['cid']));
        if (!(int)$result['affected']) {
            if (!$this->category($input['cid'])) throw new DivanApiError(409, 'category_changed', 'دسته پیش از ذخیره حذف شده است.');
            if (!$this->article($id)) throw new DivanApiError(404, 'article_not_found', 'مطلب دیگر وجود ندارد.');
        }
    }
    public function deleteArticle($id) {
        $this->query('DELETE FROM tbl_news WHERE nid = ?', array($id));
    }
}

class DivanMobileApi {
    private $store;
    private $ttl;
    private $images;
    public function __construct($store, $ttl = 86400, $images = null) {
        $this->store = $store;
        $this->images = $images;
        $this->ttl = max(60, min((int)$ttl, 604800));
    }
    private function id($value) {
        if (!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,9}$/', (string)$value)) {
            throw new DivanApiError(422, 'invalid_id', 'شناسه معتبر نیست.');
        }
        return (string)$value;
    }
    private function credentials($input) {
        if (!isset($input['username'], $input['password']) ||
            !is_string($input['username']) || !is_string($input['password']) ||
            trim($input['username']) === '' || $input['password'] === '' ||
            strlen($input['username']) > 255 || strlen($input['password']) > 4096) {
            throw new DivanApiError(422, 'invalid_credentials', 'نام کاربری و رمز را وارد کنید.');
        }
        return array(strtolower(trim($input['username'])), $input['password']);
    }
    private function principal($bearer, $now) {
        if (!is_string($bearer) || !preg_match('/^[a-f0-9]{64}$/', $bearer)) {
            throw new DivanApiError(401, 'unauthorized', 'ابتدا وارد حساب شوید.');
        }
        $hash = hash('sha256', $bearer);
        $token = $this->store->token($hash);
        $user = $token ? $this->store->user($token['username']) : null;
        if (!$token || (int)$token['expires_at'] <= $now || !$user ||
            !hash_equals($token['password_digest'], hash('sha256', $user['Password']))) {
            throw new DivanApiError(401, 'unauthorized', 'ورود منقضی شده است.');
        }
        return $token['username'];
    }
    private function randomToken() {
        if (function_exists('random_bytes')) return bin2hex(random_bytes(32));
        if (!function_exists('openssl_random_pseudo_bytes')) throw new RuntimeException('Secure random source unavailable');
        $strong = false;
        $bytes = openssl_random_pseudo_bytes(32, $strong);
        if (!$strong || $bytes === false || strlen($bytes) !== 32) {
            throw new RuntimeException('Secure random source unavailable');
        }
        return bin2hex($bytes);
    }
    public function handle($method, $action, $input, $bearer, $ip, $now = null, $files = array()) {
        $now = $now === null ? time() : $now;
        if ($action === '') {
            if ($method !== 'GET') throw new DivanApiError(405, 'method_not_allowed', 'این مسیر فقط برای خواندن است.');
            foreach (array('cat_id', 'nid') as $key) {
                if (isset($input[$key])) $input[$key] = $this->id($input[$key]);
            }
            if (isset($input['latest_news'])) {
                $input['latest_news'] = min(500, (int)$this->id($input['latest_news']));
            }
            $rows = $this->store->articles($input);
            // Mirror api.php including [] for no rows and string-valued fields.
            foreach ($rows as &$row) foreach ($row as &$value) {
                if ($value !== null) $value = (string)$value;
            }
            return $rows ? array('AndroidEbookApp' => $rows) : array();
        }
        if (!in_array($action, array('login', 'me', 'logout', 'create', 'update', 'delete', 'stats', 'posts', 'account', 'account_update', 'category_create', 'category_update', 'category_delete'), true)) {
            throw new DivanApiError(404, 'unknown_action', 'این عملیات وجود ندارد.');
        }
        $requiredMethod = in_array($action, array('me', 'stats', 'posts', 'account'), true) ? 'GET' : 'POST';
        if ($method !== $requiredMethod) throw new DivanApiError(405, 'method_not_allowed', 'روش درخواست معتبر نیست.');
        if ($action === 'login') {
            list($username, $password) = $this->credentials($input);
            $ipHash = hash('sha256', $ip);
            if ($this->store->failedLogins($ipHash, $now - 900) >= 10) {
                throw new DivanApiError(429, 'rate_limited', 'تعداد تلاش‌های ورود زیاد است؛ ۱۵ دقیقه بعد تلاش کنید.');
            }
            $user = $this->store->user($username);
            // Exactly the existing panel's hash; no account/password migration.
            if (!$user || !hash_equals($user['Password'], hash('sha256', $username.$password))) {
                $this->store->failLogin($ipHash, $now);
                throw new DivanApiError(401, 'invalid_credentials', 'نام کاربری یا رمز پذیرفته نشد.');
            }
            $token = $this->randomToken();
            $this->store->issueToken(hash('sha256', $token), $user['Username'], hash('sha256', $user['Password']), $now, $now + $this->ttl);
            return array('ok' => true, 'access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $this->ttl, 'username' => $user['Username']);
        }
        $username = $this->principal($bearer, $now);
        if ($action === 'me') return array('ok' => true, 'username' => $username);
        if ($action === 'logout') {
            $this->store->revokeToken(hash('sha256', $bearer));
            return array('ok' => true);
        }
        if ($action === 'stats') return array('ok' => true, 'stats' => $this->store->stats());
        if ($action === 'posts') {
            $page = isset($input['page']) ? (int)$this->id($input['page']) : 1;
            if ($page > 1000000) throw new DivanApiError(422, 'invalid_page', 'شماره صفحه معتبر نیست.');
            $search = isset($input['q']) ? $input['q'] : '';
            if (!is_string($search) || strlen($search) > 400) throw new DivanApiError(422, 'invalid_search', 'جستجو معتبر نیست.');
            $category = isset($input['category_id']) ? $this->id($input['category_id']) : null;
            return array_merge(array('ok' => true), $this->store->posts($page, $search, $category));
        }
        if ($action === 'account') return array('ok' => true, 'account' => $this->store->account($username));
        if ($action === 'account_update') {
            $email = isset($input['email']) ? $input['email'] : null;
            if (!is_string($email) || strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new DivanApiError(422, 'invalid_email', 'ایمیل معتبر وارد کنید.');
            $passwordHash = null;
            $changePassword = isset($input['new_password']) && $input['new_password'] !== '';
            if ($changePassword) {
                foreach (array('old_password', 'new_password', 'confirm_password') as $key) {
                    if (!isset($input[$key]) || !is_string($input[$key]) || strlen($input[$key]) > 4096) throw new DivanApiError(422, 'invalid_password', 'رمزهای ورود را کامل کنید.');
                }
                $user = $this->store->user($username);
                if (!hash_equals($user['Password'], hash('sha256', strtolower($username).$input['old_password']))) throw new DivanApiError(422, 'invalid_password', 'رمز فعلی درست نیست.');
                if ($input['new_password'] !== $input['confirm_password']) throw new DivanApiError(422, 'password_mismatch', 'تکرار رمز مطابقت ندارد.');
                $passwordHash = hash('sha256', strtolower($username).$input['new_password']);
            }
            $this->store->updateAccount($username, $email, $passwordHash);
            return array('ok' => true, 'reauthenticate' => $changePassword);
        }
        if ($action === 'category_delete') {
            $id = $this->id(isset($input['id']) ? $input['id'] : null);
            $affected = $this->store->deleteCategory($id);
            if (!$affected && $this->store->category($id)) throw new DivanApiError(409, 'category_not_empty', 'دسته دارای مطلب است؛ ابتدا مطالب را منتقل یا حذف کنید.');
            return array('ok' => true, 'cid' => $id);
        }
        if ($action === 'category_create' || $action === 'category_update') {
            foreach (array('category_name' => 255, 'author' => 50) as $key => $limit) {
                if (!isset($input[$key]) || !is_string($input[$key]) || trim($input[$key]) === '' || !preg_match('//u', $input[$key]) || preg_match_all('/./us', $input[$key], $unused) > $limit || preg_match('/[\xF0-\xF4][\x80-\xBF]{3}/', $input[$key])) throw new DivanApiError(422, 'invalid_category', 'نام دسته و نویسنده را کامل و با طول مجاز وارد کنید.');
            }
            $previous = null;
            if ($action === 'category_update') {
                $id = $this->id(isset($input['id']) ? $input['id'] : null);
                $previous = $this->store->categoryRecord($id);
                if (!$previous) throw new DivanApiError(404, 'category_not_found', 'دسته وجود ندارد.');
            }
            $image = $previous ? $previous['category_image'] : '';
            if (isset($files['category_image']) && $files['category_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                if (!$this->images) throw new RuntimeException('Image storage unavailable');
                $image = $this->images->save($files['category_image']);
            } elseif ($previous === null) throw new DivanApiError(422, 'image_required', 'تصویر دسته را انتخاب کنید.');
            if ($previous) $this->store->updateCategory($id, $input, $image);
            else $id = $this->store->createCategory($input, $image);
            return array('ok' => true, 'cid' => $id);
        }
        if ($action === 'delete') {
            $id = $this->id(isset($input['id']) ? $input['id'] : null);
            $this->store->deleteArticle($id);
            return array('ok' => true, 'nid' => $id);
        }
        foreach (array('news_heading', 'news_date', 'news_description') as $key) {
            if (!isset($input[$key]) || !is_string($input[$key]) || trim($input[$key]) === '') {
                throw new DivanApiError(422, 'missing_field', 'عنوان، عنوان فرعی، دسته و متن را کامل کنید.');
            }
        }
        if (preg_match_all('/./us', $input['news_heading'], $unused) > 500 || preg_match_all('/./us', $input['news_date'], $unused) > 255 || strlen($input['news_description']) > 65535) {
            throw new DivanApiError(422, 'field_too_long', 'طول متن بیش از حد مجاز است.');
        }
        foreach (array('news_heading', 'news_date', 'news_description') as $key) {
            if (!preg_match('//u', $input[$key]) || preg_match('/[\xF0-\xF4][\x80-\xBF]{3}/', $input[$key])) {
                throw new DivanApiError(422, 'unsupported_text', 'متن باید UTF-8 باشد؛ دیتابیس فعلی نویسه‌های چهار بایتی مثل ایموجی را پشتیبانی نمی‌کند.');
            }
        }
        $input['cid'] = $this->id(isset($input['cid']) ? $input['cid'] : null);
        if (!$this->store->category($input['cid'])) throw new DivanApiError(422, 'category_not_found', 'دسته روی سرور وجود ندارد.');
        if ($action === 'update') {
            $id = $this->id(isset($input['id']) ? $input['id'] : null);
            if (!$this->store->article($id)) throw new DivanApiError(404, 'article_not_found', 'مطلب روی سرور وجود ندارد.');
            $this->store->updateArticle($id, $input);
        } else {
            $id = $this->store->createArticle($input);
        }
        return array('ok' => true, 'nid' => $id);
    }
}
