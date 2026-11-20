<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DivanRepository
{
    protected function query($sql, $params = [])
    {
        if (str_starts_with($sql, 'SELECT')) {
            return array_map(fn ($row) => (array) $row, DB::select($sql, $params));
        }
        $affected = DB::affectingStatement($sql, $params);

        return ['affected' => $affected, 'id' => DB::getPdo()->lastInsertId()];
    }

    public function user($username)
    {
        $rows = $this->query('SELECT Username, Password FROM tbl_user WHERE Username = ?', [$username]);

        return count($rows) === 1 ? $rows[0] : null;
    }

    public function failedLogins($ip, $since)
    {
        $this->query('DELETE FROM divan_api_login_attempts WHERE attempted_at < ?', [$since]);
        $rows = $this->query('SELECT COUNT(*) AS total FROM divan_api_login_attempts WHERE ip_hash = ? AND attempted_at >= ?', [$ip, $since]);

        return (int) $rows[0]['total'];
    }

    public function failLogin($ip, $now)
    {
        $this->query('INSERT INTO divan_api_login_attempts (ip_hash, attempted_at) VALUES (?, ?)', [$ip, $now]);
    }

    public function issueToken($hash, $username, $passwordDigest, $now, $expires)
    {
        $this->query('DELETE FROM divan_api_tokens WHERE expires_at <= ?', [$now]);
        $this->query('INSERT INTO divan_api_tokens (token_hash, username, password_digest, created_at, expires_at) VALUES (?, ?, ?, ?, ?)', [$hash, $username, $passwordDigest, $now, $expires]);
    }

    public function token($hash)
    {
        $rows = $this->query('SELECT username, password_digest, expires_at FROM divan_api_tokens WHERE token_hash = ?', [$hash]);

        return count($rows) === 1 ? $rows[0] : null;
    }

    public function revokeToken($hash)
    {
        $this->query('DELETE FROM divan_api_tokens WHERE token_hash = ?', [$hash]);
    }

    public function categoryRecord($id)
    {
        $rows = $this->query('SELECT cid, category_name, category_image, author, status FROM tbl_news_category WHERE cid = ?', [$id]);

        return count($rows) === 1 ? $rows[0] : null;
    }

    public function ensureCategoryOrderTable(): void
    {
        // Separate InnoDB storage keeps reordering atomic even with legacy MyISAM categories.
        if (! Schema::hasTable('divan_category_order')) {
            Schema::create('divan_category_order', function ($table) {
                $table->engine = 'InnoDB';
                $table->unsignedTinyInteger('id')->primary();
                $table->longText('category_ids');
            });
        }
        DB::table('divan_category_order')->insertOrIgnore(['id' => 1, 'category_ids' => '[]']);
    }

    public static function orderedCategories(array $rows, array $ids): array
    {
        $positions = array_flip($ids);
        usort($rows, static fn ($a, $b) =>
            (($positions[(string) $a['cid']] ?? PHP_INT_MAX) <=> ($positions[(string) $b['cid']] ?? PHP_INT_MAX))
            ?: ((int) $b['cid'] <=> (int) $a['cid']));

        return $rows;
    }

    public function reorderCategories(array $ids, array $previous): void
    {
        $this->ensureCategoryOrderTable();
        DB::transaction(function () use ($ids, $previous) {
            $order = DB::table('divan_category_order')->where('id', 1)->lockForUpdate()->first();
            $rows = $this->query('SELECT cid FROM tbl_news_category ORDER BY cid DESC');
            $current = array_map(static fn ($row) => (string) $row['cid'], self::orderedCategories($rows, json_decode($order->category_ids, true, 512, JSON_THROW_ON_ERROR)));
            $requestedSet = $ids;
            $currentSet = $current;
            sort($requestedSet);
            sort($currentSet);
            if ($requestedSet !== $currentSet || ($current !== $previous && $current !== $ids)) {
                throw new ApiError(409, 'category_order_changed', 'فهرست یا ترتیب دسته‌ها تغییر کرده است. فهرست را تازه کنید و دوباره مرتب کنید.');
            }
            DB::table('divan_category_order')->where('id', 1)->update(['category_ids' => json_encode($ids, JSON_THROW_ON_ERROR)]);
        });
    }

    public function createCategory($input, $image)
    {
        $result = $this->query('INSERT INTO tbl_news_category (category_name, category_image, author) VALUES (?, ?, ?)', [$input['category_name'], $image, $input['author']]);

        return (string) $result['id'];
    }

    public function updateCategory($id, $input, $image)
    {
        $this->query('UPDATE tbl_news_category SET category_name = ?, author = ?, category_image = ? WHERE cid = ?', [$input['category_name'], $input['author'], $image, $id]);
    }

    public function deleteCategory($id)
    {
        // Legacy MyISAM cannot atomically cascade. Only empty categories may be
        // deleted; move/delete their articles explicitly first.
        $result = $this->query('DELETE FROM tbl_news_category WHERE cid = ? AND NOT EXISTS (SELECT 1 FROM tbl_news WHERE cat_id = ?)', [$id, $id]);

        return (int) $result['affected'];
    }

    public function stats()
    {
        $rows = $this->query('SELECT (SELECT COUNT(*) FROM tbl_news_category) AS categories, (SELECT COUNT(*) FROM tbl_news) AS posts');

        return $rows[0];
    }

    public function pages()
    {
        $rows = $this->query("SELECT slug, title, html_body, revision, updated_at FROM divan_pages ORDER BY FIELD(slug, 'first-talk', 'last-talk', 'contact')");

        return array_map(function ($row) {
            $row['revision'] = (int) $row['revision'];
            $row['updated_at'] = gmdate('Y-m-d\TH:i:s\Z', (int) $row['updated_at']);

            return $row;
        }, $rows);
    }

    public function updatePage($slug, $title, $body, $revision, $now)
    {
        $result = $this->query('UPDATE divan_pages SET title = ?, html_body = ?, revision = revision + 1, updated_at = ? WHERE slug = ? AND revision = ?', [$title, $body, $now, $slug, $revision]);

        return (int) $result['affected'];
    }

    public function posts($page, $search, $category)
    {
        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = '(news_heading LIKE ? OR news_description LIKE ?)';
            $params[] = '%'.$search.'%';
            $params[] = '%'.$search.'%';
        }
        if ($category !== null) {
            $where[] = 'cat_id = ?';
            $params[] = $category;
        }
        $filter = $where ? ' WHERE '.implode(' AND ', $where) : '';
        $total = $this->query('SELECT COUNT(*) AS total FROM tbl_news'.$filter, $params);
        $rows = $this->query('SELECT nid, news_heading, cat_id, news_date, created_at, updated_at FROM tbl_news'.$filter.' ORDER BY nid DESC LIMIT 50 OFFSET '.(($page - 1) * 50), $params);

        return ['posts' => array_map([$this, 'formatArticleDates'], $rows), 'total' => (int) $total[0]['total'], 'page' => $page];
    }

    public function account($username)
    {
        $rows = $this->query('SELECT Username, Email FROM tbl_user WHERE Username = ?', [$username]);

        return $rows[0];
    }

    public function updateAccount($username, $email, $passwordHash)
    {
        if ($passwordHash === null) {
            $this->query('UPDATE tbl_user SET Email = ? WHERE Username = ?', [$email, $username]);
        } else {
            $this->query('UPDATE tbl_user SET Email = ?, Password = ? WHERE Username = ?', [$email, $passwordHash, $username]);
        }
    }

    public function category($id)
    {
        $rows = $this->query('SELECT cid FROM tbl_news_category WHERE cid = ?', [$id]);

        return count($rows) === 1;
    }

    public function article($id)
    {
        $rows = $this->articles(['nid' => $id]);

        return count($rows) === 1 ? $rows[0] : null;
    }

    public function articles($query)
    {
        // Explicit legacy columns: future unrelated schema additions do not leak.
        // Keep the exact old Android JSON shape unless the Flutter reader opts in.
        $includeDates = ($query['include_dates'] ?? null) === '1';
        $dateColumns = $includeDates ? ', n.created_at, n.updated_at' : '';
        $includeViews = ($query['include_views'] ?? null) === '1';
        $viewJoin = $includeViews ? ' LEFT JOIN divan_post_views pv ON pv.post_id = n.nid' : '';
        $viewColumn = $includeViews ? ', COALESCE(pv.views, 0) AS view_count' : '';
        $sql = 'SELECT c.cid, c.category_name, c.category_image, c.author, c.status, n.nid, n.news_heading, n.cat_id, n.news_status, n.news_date, n.news_image, n.news_description'.$dateColumns.$viewColumn.' FROM tbl_news_category c JOIN tbl_news n ON c.cid = n.cat_id'.$viewJoin;
        $format = $includeDates ? [$this, 'formatArticleDates'] : null;
        $map = static fn ($rows) => $format ? array_map($format, $rows) : $rows;
        if (isset($query['cat_id'])) {
            return $map($this->query($sql.' WHERE c.cid = ? ORDER BY n.nid ASC', [$query['cat_id']]));
        }
        if (isset($query['nid'])) {
            return $map($this->query($sql.' WHERE n.nid = ?', [$query['nid']]));
        }
        if (isset($query['latest_news'])) {
            return $map($this->query($sql.' ORDER BY n.nid ASC LIMIT '.(int) $query['latest_news']));
        }

        $rows = $this->query('SELECT cid, category_name, category_image, author, status FROM tbl_news_category ORDER BY cid DESC');
        $ids = Schema::hasTable('divan_category_order')
            ? json_decode(DB::table('divan_category_order')->where('id', 1)->value('category_ids') ?? '[]', true, 512, JSON_THROW_ON_ERROR)
            : [];

        return self::orderedCategories($rows, $ids);
    }

    private function formatArticleDates($row)
    {
        foreach (['created_at', 'updated_at'] as $field) {
            $row[$field] = $row[$field] === null ? null : gmdate('Y-m-d\\TH:i:s\\Z', (int) $row[$field]);
        }

        return $row;
    }

    public function createArticle($input, $now)
    {
        // INSERT...SELECT locks the category for this statement on MyISAM too.
        $result = $this->query("INSERT INTO tbl_news (news_heading, cat_id, news_date, news_description, news_image, created_at, updated_at) SELECT ?, cid, ?, ?, '', ?, ? FROM tbl_news_category WHERE cid = ?", [$input['news_heading'], $input['news_date'], $input['news_description'], $now, $now, $input['cid']]);
        if (! (int) $result['affected']) {
            throw new ApiError(409, 'category_changed', 'دسته پیش از ذخیره حذف شده است.');
        }

        return (string) $result['id'];
    }

    public function updateArticle($id, $input, $now)
    {
        $result = $this->query('UPDATE tbl_news SET news_heading = ?, cat_id = ?, news_date = ?, news_description = ?, updated_at = ? WHERE nid = ? AND EXISTS (SELECT 1 FROM tbl_news_category WHERE cid = ?)', [$input['news_heading'], $input['cid'], $input['news_date'], $input['news_description'], $now, $id, $input['cid']]);
        if (! (int) $result['affected']) {
            if (! $this->category($input['cid'])) {
                throw new ApiError(409, 'category_changed', 'دسته پیش از ذخیره حذف شده است.');
            }
            if (! $this->article($id)) {
                throw new ApiError(404, 'article_not_found', 'مطلب دیگر وجود ندارد.');
            }
        }
    }

    public function deleteArticle($id)
    {
        $this->query('DELETE FROM tbl_news WHERE nid = ?', [$id]);
        $this->query('DELETE FROM divan_post_views WHERE post_id = ?', [$id]);
    }

    public function incrementArticleViews($id)
    {
        $this->query('INSERT INTO divan_post_views (post_id, views) VALUES (?, 1) ON DUPLICATE KEY UPDATE views = views + 1', [$id]);
        $rows = $this->query('SELECT views FROM divan_post_views WHERE post_id = ?', [$id]);

        return (int) ($rows[0]['views'] ?? 0);
    }

    public function articleViews($id)
    {
        $rows = $this->query('SELECT views FROM divan_post_views WHERE post_id = ?', [$id]);

        return (int) ($rows[0]['views'] ?? 0);
    }

    public function mediaName($filename)
    {
        $rows = $this->query('SELECT display_name FROM divan_media_names WHERE filename = ?', [$filename]);

        return $rows[0]['display_name'] ?? null;
    }

    public function saveMediaName($filename, $name)
    {
        $this->query('INSERT INTO divan_media_names (filename, display_name, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), updated_at = VALUES(updated_at)', [$filename, $name, time()]);
    }

    public function deleteMediaName($filename)
    {
        $this->query('DELETE FROM divan_media_names WHERE filename = ?', [$filename]);
    }

    public function supportCountFromIp($ipHash, $since)
    {
        $rows = $this->query('SELECT COUNT(*) AS total FROM divan_support_messages WHERE source_ip_hash = ? AND created_at >= ?', [$ipHash, $since]);

        return (int) $rows[0]['total'];
    }

    public function ensureSupportUsersColumns(): void
    {
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('divan_support_users')) {
                \Illuminate\Support\Facades\Schema::table('divan_support_users', function (\Illuminate\Database\Schema\Blueprint $table) {
                    if (! \Illuminate\Support\Facades\Schema::hasColumn('divan_support_users', 'father_name')) {
                        $table->string('father_name', 120)->nullable()->after('name');
                    }
                    if (! \Illuminate\Support\Facades\Schema::hasColumn('divan_support_users', 'is_admin')) {
                        $table->boolean('is_admin')->default(false)->after('mobile');
                    }
                });
            }
        } catch (\Throwable) {}
    }

    public function supportUserByPhone($mobile)
    {
        $this->ensureSupportUsersColumns();
        $rows = $this->query('SELECT id, name, mobile, father_name, is_admin FROM divan_support_users WHERE mobile = ?', [$mobile]);

        return $rows[0] ?? null;
    }

    public function supportUser($id)
    {
        $this->ensureSupportUsersColumns();
        $rows = $this->query('SELECT id, name, mobile, father_name, is_admin FROM divan_support_users WHERE id = ?', [$id]);

        return $rows[0] ?? null;
    }

    public function supportUsers()
    {
        $this->ensureSupportUsersColumns();
        return $this->query('SELECT u.id, u.name, u.mobile, u.father_name, u.is_admin, u.created_at, COUNT(m.id) AS support_messages FROM divan_support_users u LEFT JOIN divan_support_messages m ON m.user_id = u.id GROUP BY u.id, u.name, u.mobile, u.father_name, u.is_admin, u.created_at ORDER BY u.id DESC LIMIT 1000');
    }

    public function saveSupportUser($name, $mobile, $now, $fatherName = null)
    {
        $this->ensureSupportUsersColumns();
        $this->query('INSERT INTO divan_support_users (name, mobile, father_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), father_name = COALESCE(VALUES(father_name), father_name), updated_at = VALUES(updated_at)', [$name, $mobile, $fatherName, $now, $now]);

        return $this->supportUserByPhone($mobile);
    }

    public function setUserAdmin($id, bool $isAdmin): void
    {
        $this->ensureSupportUsersColumns();
        $this->query('UPDATE divan_support_users SET is_admin = ? WHERE id = ?', [$isAdmin ? 1 : 0, $id]);
    }

    public function deleteOldSupportOtps($since)
    {
        $this->query('DELETE FROM divan_support_otps WHERE created_at < ?', [$since]);
    }

    public function supportOtpCountFromIp($ipHash, $since)
    {
        $rows = $this->query('SELECT COUNT(*) AS total FROM divan_support_otps WHERE source_ip_hash = ? AND created_at >= ?', [$ipHash, $since]);

        return (int) $rows[0]['total'];
    }

    public function createSupportOtp($challengeId, $userId, $mobile, $codeHash, $ipHash, $now, $expires)
    {
        $this->query('INSERT INTO divan_support_otps (challenge_id, user_id, mobile, code_hash, source_ip_hash, attempts, created_at, expires_at, consumed_at) VALUES (?, ?, ?, ?, ?, 0, ?, ?, NULL)', [$challengeId, $userId, $mobile, $codeHash, $ipHash, $now, $expires]);
    }

    public function supportOtp($challengeId)
    {
        $rows = $this->query('SELECT challenge_id, user_id, mobile, code_hash, attempts, expires_at, consumed_at FROM divan_support_otps WHERE challenge_id = ?', [$challengeId]);

        return $rows[0] ?? null;
    }

    public function incrementSupportOtpAttempts($challengeId)
    {
        $this->query('UPDATE divan_support_otps SET attempts = attempts + 1 WHERE challenge_id = ?', [$challengeId]);
    }

    public function consumeSupportOtp($challengeId, $now)
    {
        $this->query('UPDATE divan_support_otps SET consumed_at = ? WHERE challenge_id = ?', [$now, $challengeId]);
    }

    public function issueSupportToken($hash, $userId, $now, $expires)
    {
        $this->query('DELETE FROM divan_support_tokens WHERE expires_at <= ?', [$now]);
        $this->query('INSERT INTO divan_support_tokens (token_hash, user_id, created_at, expires_at) VALUES (?, ?, ?, ?)', [$hash, $userId, $now, $expires]);
    }

    public function supportToken($hash)
    {
        $rows = $this->query('SELECT user_id, expires_at FROM divan_support_tokens WHERE token_hash = ?', [$hash]);

        return $rows[0] ?? null;
    }

    public function revokeSupportToken($hash)
    {
        $this->query('DELETE FROM divan_support_tokens WHERE token_hash = ?', [$hash]);
    }

    public function supportMessageCountForUser($userId, $since)
    {
        $rows = $this->query('SELECT COUNT(*) AS total FROM divan_support_messages WHERE user_id = ? AND created_at >= ?', [$userId, $since]);

        return (int) $rows[0]['total'];
    }

    public function createSupportMessage($userId, $ipHash, $message, $now)
    {
        $this->query('INSERT INTO divan_support_messages (receipt_hash, source_ip_hash, user_id, message, reply, created_at, replied_at) VALUES (NULL, ?, ?, ?, NULL, ?, NULL)', [$ipHash, $userId, $message, $now]);
    }

    public function supportMessagesForUser($userId)
    {
        $rows = $this->query('SELECT id, message, reply, created_at, replied_at FROM divan_support_messages WHERE user_id = ? ORDER BY id DESC LIMIT 100', [$userId]);
        return $this->attachRepliesToTickets($rows);
    }

    public function supportMessage($receiptHash)
    {
        $rows = $this->query('SELECT id, message, reply, created_at, replied_at FROM divan_support_messages WHERE receipt_hash = ?', [$receiptHash]);

        return $rows[0] ?? null;
    }

    public function supportMessageById($id)
    {
        $rows = $this->query('SELECT id, user_id, message, reply, created_at, replied_at FROM divan_support_messages WHERE id = ?', [$id]);

        return $rows[0] ?? null;
    }

    public function supportMessages()
    {
        $rows = $this->query('SELECT m.id, m.message, m.reply, m.created_at, m.replied_at, u.name AS user_name, u.mobile AS user_mobile FROM divan_support_messages AS m LEFT JOIN divan_support_users AS u ON u.id = m.user_id ORDER BY m.id DESC LIMIT 200');
        return $this->attachRepliesToTickets($rows);
    }

    public function supportExists($id)
    {
        return count($this->query('SELECT id FROM divan_support_messages WHERE id = ?', [$id])) === 1;
    }

    public function createSupportReply($ticketId, $sender, $message, $now)
    {
        $recent = $this->query(
            'SELECT id FROM divan_support_replies WHERE ticket_id = ? AND sender = ? AND message = ? AND created_at >= ? LIMIT 1',
            [$ticketId, $sender, $message, $now - 5]
        );
        if (! empty($recent)) {
            return (string) $recent[0]['id'];
        }

        $result = $this->query('INSERT INTO divan_support_replies (ticket_id, sender, message, created_at) VALUES (?, ?, ?, ?)', [$ticketId, $sender, $message, $now]);

        return (string) ($result['id'] ?? '');
    }

    public function supportRepliesForTickets(array $ticketIds)
    {
        $cleanIds = array_values(array_filter(array_map('intval', $ticketIds), static fn ($id) => $id > 0));
        if (empty($cleanIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));

        try {
            return $this->query("SELECT id, ticket_id, sender, message, created_at FROM divan_support_replies WHERE ticket_id IN ($placeholders) ORDER BY id ASC", $cleanIds);
        } catch (\Throwable) {
            return [];
        }
    }

    private function attachRepliesToTickets(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }
        $ids = array_column($rows, 'id');
        $allReplies = $this->supportRepliesForTickets($ids);
        $grouped = [];
        foreach ($allReplies as $r) {
            $grouped[$r['ticket_id']][] = $r;
        }
        foreach ($rows as &$row) {
            $row['replies'] = $grouped[$row['id']] ?? [];
        }
        unset($row);

        return $rows;
    }

    public function replyToSupport($id, $reply, $now)
    {
        if (! $this->supportExists($id)) {
            return 0;
        }
        if ($reply !== null && trim($reply) !== '') {
            $this->createSupportReply($id, 'admin', trim($reply), $now);
        }
        $result = $this->query('UPDATE divan_support_messages SET reply = ?, replied_at = ? WHERE id = ?', [$reply, $now, $id]);

        return 1;
    }

    public function deleteSupportMessage($id)
    {
        $this->query('DELETE FROM divan_support_messages WHERE id = ?', [$id]);
        try {
            $this->query('DELETE FROM divan_support_replies WHERE ticket_id = ?', [$id]);
        } catch (\Throwable) {}
    }

    public function supportReplyById($id)
    {
        $rows = $this->query('SELECT id, ticket_id, sender, message, created_at FROM divan_support_replies WHERE id = ?', [$id]);

        return $rows[0] ?? null;
    }

    public function updateSupportReply($id, $message)
    {
        $reply = $this->supportReplyById($id);
        if (! $reply) {
            return false;
        }
        $this->query('UPDATE divan_support_replies SET message = ? WHERE id = ?', [$message, $id]);
        $this->syncTicketLatestReply($reply['ticket_id']);

        return true;
    }

    public function deleteSupportReply($id)
    {
        $reply = $this->supportReplyById($id);
        if (! $reply) {
            return false;
        }
        $this->query('DELETE FROM divan_support_replies WHERE id = ?', [$id]);
        $this->syncTicketLatestReply($reply['ticket_id']);

        return true;
    }

    public function syncTicketLatestReply($ticketId)
    {
        $rows = $this->query('SELECT message, created_at FROM divan_support_replies WHERE ticket_id = ? AND sender = ? ORDER BY id DESC LIMIT 1', [$ticketId, 'admin']);
        if (! empty($rows)) {
            $this->query('UPDATE divan_support_messages SET reply = ?, replied_at = ? WHERE id = ?', [$rows[0]['message'], $rows[0]['created_at'], $ticketId]);
        } else {
            $this->query('UPDATE divan_support_messages SET reply = NULL, replied_at = NULL WHERE id = ?', [$ticketId]);
        }
    }

    public function ensureAppReleasesTable(): void
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('divan_app_releases')) {
                \Illuminate\Support\Facades\Schema::create('divan_app_releases', function (\Illuminate\Database\Schema\Blueprint $table) {
                    $table->bigIncrements('id');
                    $table->string('version', 50);
                    $table->unsignedInteger('build_number')->default(1);
                    $table->string('filename', 255);
                    $table->string('file_path', 500);
                    $table->unsignedBigInteger('size_bytes')->default(0);
                    $table->text('changelog')->nullable();
                    $table->boolean('is_latest')->default(false)->index();
                    $table->unsignedInteger('created_at')->index();
                    $table->charset = 'utf8mb4';
                    $table->collation = 'utf8mb4_unicode_ci';
                });
            }
        } catch (\Throwable) {}
    }

    public function appReleases(): array
    {
        $this->ensureAppReleasesTable();
        $rows = [];
        try {
            $rows = $this->query('SELECT id, version, build_number, filename, file_path, size_bytes, changelog, is_latest, created_at FROM divan_app_releases ORDER BY is_latest DESC, created_at DESC, id DESC');
        } catch (\Throwable) {
            $rows = [];
        }

        if (empty($rows)) {
            $candidates = [
                storage_path('app/apk/divan-ansaralhossein.apk'),
                public_path('download/divan-ansaralhossein.apk'),
                base_path('../divan-ansaralhossein.apk'),
                base_path('../build/app/outputs/flutter-apk/app-release.apk'),
            ];
            foreach ($candidates as $path) {
                if (file_exists($path) && is_readable($path)) {
                    $destDir = storage_path('app/apk');
                    if (! is_dir($destDir)) {
                        @mkdir($destDir, 0755, true);
                    }
                    $versionedPath = $destDir.DIRECTORY_SEPARATOR.'divan-ansaralhossein-v0.1.0.apk';
                    if (! file_exists($versionedPath)) {
                        @copy($path, $versionedPath);
                    }
                    $this->createAppRelease([
                        'version' => '0.1.0',
                        'build_number' => 1,
                        'filename' => 'divan-ansaralhossein-v0.1.0.apk',
                        'file_path' => file_exists($versionedPath) ? $versionedPath : $path,
                        'size_bytes' => filesize($path),
                        'changelog' => 'نسخهٔ اولیه اپلیکیشن دیوان انصارالحسین(ع)',
                        'is_latest' => 1,
                        'created_at' => filemtime($path) ?: time(),
                    ]);
                    try {
                        $rows = $this->query('SELECT id, version, build_number, filename, file_path, size_bytes, changelog, is_latest, created_at FROM divan_app_releases ORDER BY is_latest DESC, created_at DESC, id DESC');
                    } catch (\Throwable) {}
                    break;
                }
            }
        }

        return $rows;
    }

    public function appReleaseById(int $id): ?array
    {
        $this->ensureAppReleasesTable();
        try {
            $rows = $this->query('SELECT id, version, build_number, filename, file_path, size_bytes, changelog, is_latest, created_at FROM divan_app_releases WHERE id = ?', [$id]);
            return $rows[0] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function appReleaseByVersion(string $version): ?array
    {
        $this->ensureAppReleasesTable();
        try {
            $rows = $this->query('SELECT id, version, build_number, filename, file_path, size_bytes, changelog, is_latest, created_at FROM divan_app_releases WHERE version = ? LIMIT 1', [$version]);
            return $rows[0] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function latestAppRelease(): ?array
    {
        $this->ensureAppReleasesTable();
        try {
            $rows = $this->query('SELECT id, version, build_number, filename, file_path, size_bytes, changelog, is_latest, created_at FROM divan_app_releases WHERE is_latest = 1 ORDER BY created_at DESC LIMIT 1');
            if (! empty($rows)) {
                return $rows[0];
            }
            $all = $this->appReleases();
            return $all[0] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function createAppRelease(array $data): array
    {
        $this->ensureAppReleasesTable();
        $isLatest = ! empty($data['is_latest']) ? 1 : 0;
        if ($isLatest) {
            try {
                $this->query('UPDATE divan_app_releases SET is_latest = 0');
            } catch (\Throwable) {}
        }
        $result = $this->query(
            'INSERT INTO divan_app_releases (version, build_number, filename, file_path, size_bytes, changelog, is_latest, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['version'],
                (int) ($data['build_number'] ?? 1),
                $data['filename'],
                $data['file_path'],
                (int) ($data['size_bytes'] ?? 0),
                $data['changelog'] ?? '',
                $isLatest,
                (int) ($data['created_at'] ?? time()),
            ]
        );
        $newId = (int) $result['id'];
        return $this->appReleaseById($newId) ?? array_merge($data, ['id' => $newId]);
    }

    public function setLatestAppRelease(int $id): bool
    {
        $this->ensureAppReleasesTable();
        $release = $this->appReleaseById($id);
        if (! $release) {
            return false;
        }
        $this->query('UPDATE divan_app_releases SET is_latest = 0');
        $this->query('UPDATE divan_app_releases SET is_latest = 1 WHERE id = ?', [$id]);

        if (! empty($release['file_path']) && file_exists($release['file_path'])) {
            $dest = storage_path('app/apk/divan-ansaralhossein.apk');
            @copy($release['file_path'], $dest);
            $publicDest = public_path('download/divan-ansaralhossein.apk');
            if (is_dir(public_path('download'))) {
                @copy($release['file_path'], $publicDest);
            }
        }
        return true;
    }

    public function deleteAppRelease(int $id): bool
    {
        $this->ensureAppReleasesTable();
        $release = $this->appReleaseById($id);
        if (! $release) {
            return false;
        }
        if (! empty($release['file_path']) && file_exists($release['file_path'])) {
            @unlink($release['file_path']);
        }
        $this->query('DELETE FROM divan_app_releases WHERE id = ?', [$id]);

        if (! empty($release['is_latest'])) {
            $remaining = $this->query('SELECT id FROM divan_app_releases ORDER BY created_at DESC LIMIT 1');
            if (! empty($remaining)) {
                $this->setLatestAppRelease((int) $remaining[0]['id']);
            }
        }
        return true;
    }

    public function ensureAnnouncementsTables(): void
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('divan_announcements')) {
                \Illuminate\Support\Facades\Schema::create('divan_announcements', function (\Illuminate\Database\Schema\Blueprint $table) {
                    $table->bigIncrements('id');
                    $table->string('title', 500);
                    $table->string('subtitle', 255)->nullable();
                    $table->longText('description');
                    $table->unsignedInteger('created_at')->index();
                    $table->unsignedInteger('updated_at')->index();
                    $table->charset = 'utf8mb4';
                    $table->collation = 'utf8mb4_unicode_ci';
                });
            }
            if (! \Illuminate\Support\Facades\Schema::hasTable('divan_announcement_users')) {
                \Illuminate\Support\Facades\Schema::create('divan_announcement_users', function (\Illuminate\Database\Schema\Blueprint $table) {
                    $table->unsignedBigInteger('announcement_id');
                    $table->unsignedBigInteger('user_id');
                    $table->primary(['announcement_id', 'user_id']);
                    $table->index('user_id');
                    $table->charset = 'utf8mb4';
                    $table->collation = 'utf8mb4_unicode_ci';
                });
            }
            if (! \Illuminate\Support\Facades\Schema::hasTable('divan_announcement_views')) {
                \Illuminate\Support\Facades\Schema::create('divan_announcement_views', function (\Illuminate\Database\Schema\Blueprint $table) {
                    $table->unsignedBigInteger('announcement_id');
                    $table->unsignedBigInteger('user_id');
                    $table->unsignedInteger('viewed_at');
                    $table->primary(['announcement_id', 'user_id']);
                    $table->index('user_id');
                    $table->charset = 'utf8mb4';
                    $table->collation = 'utf8mb4_unicode_ci';
                });
            }
            if (! \Illuminate\Support\Facades\Schema::hasTable('divan_announcement_comments')) {
                \Illuminate\Support\Facades\Schema::create('divan_announcement_comments', function (\Illuminate\Database\Schema\Blueprint $table) {
                    $table->bigIncrements('id');
                    $table->unsignedBigInteger('announcement_id')->index();
                    $table->unsignedBigInteger('user_id')->index();
                    $table->unsignedBigInteger('parent_id')->nullable()->index();
                    $table->text('comment');
                    $table->unsignedInteger('created_at')->index();
                    $table->unsignedInteger('updated_at');
                    $table->charset = 'utf8mb4';
                    $table->collation = 'utf8mb4_unicode_ci';
                });
            }
        } catch (\Throwable) {}
    }

    public function announcements(): array
    {
        $this->ensureAnnouncementsTables();
        return $this->query('SELECT a.id, a.title, a.subtitle, a.description, a.created_at, a.updated_at, GROUP_CONCAT(u.user_id) AS user_ids FROM divan_announcements a LEFT JOIN divan_announcement_users u ON u.announcement_id = a.id GROUP BY a.id, a.title, a.subtitle, a.description, a.created_at, a.updated_at ORDER BY a.id DESC LIMIT 500');
    }

    public function announcement($id)
    {
        $this->ensureAnnouncementsTables();
        $rows = $this->query('SELECT a.id, a.title, a.subtitle, a.description, a.created_at, a.updated_at, GROUP_CONCAT(u.user_id) AS user_ids FROM divan_announcements a LEFT JOIN divan_announcement_users u ON u.announcement_id = a.id WHERE a.id = ? GROUP BY a.id, a.title, a.subtitle, a.description, a.created_at, a.updated_at', [$id]);
        return $rows[0] ?? null;
    }

    public function announcementsForUser($userId): array
    {
        $this->ensureAnnouncementsTables();
        return $this->query('SELECT a.id, a.title, a.subtitle, a.description, a.created_at, a.updated_at FROM divan_announcements a INNER JOIN divan_announcement_users u ON u.announcement_id = a.id WHERE u.user_id = ? ORDER BY a.id DESC LIMIT 200', [$userId]);
    }

    public function createAnnouncement(string $title, ?string $subtitle, string $description, array $userIds, ?int $now = null): int
    {
        $this->ensureAnnouncementsTables();
        $now = $now ?? time();
        $this->query('INSERT INTO divan_announcements (title, subtitle, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [
            $title,
            $subtitle ?: null,
            $description,
            $now,
            $now,
        ]);
        $rows = $this->query('SELECT LAST_INSERT_ID() as last_id');
        $id = (int) ($rows[0]['last_id'] ?? 0);
        if ($id > 0) {
            foreach (array_unique($userIds) as $uid) {
                $uidInt = (int) $uid;
                if ($uidInt > 0) {
                    $this->query('INSERT IGNORE INTO divan_announcement_users (announcement_id, user_id) VALUES (?, ?)', [$id, $uidInt]);
                }
            }
        }
        return $id;
    }

    public function updateAnnouncement(int $id, string $title, ?string $subtitle, string $description, array $userIds, ?int $now = null): void
    {
        $this->ensureAnnouncementsTables();
        $now = $now ?? time();
        $this->query('UPDATE divan_announcements SET title = ?, subtitle = ?, description = ?, updated_at = ? WHERE id = ?', [
            $title,
            $subtitle ?: null,
            $description,
            $now,
            $id,
        ]);
        $this->query('DELETE FROM divan_announcement_users WHERE announcement_id = ?', [$id]);
        foreach (array_unique($userIds) as $uid) {
            $uidInt = (int) $uid;
            if ($uidInt > 0) {
                $this->query('INSERT IGNORE INTO divan_announcement_users (announcement_id, user_id) VALUES (?, ?)', [$id, $uidInt]);
            }
        }
    }

    public function deleteAnnouncement(int $id): void
    {
        $this->ensureAnnouncementsTables();
        $this->query('DELETE FROM divan_announcement_users WHERE announcement_id = ?', [$id]);
        $this->query('DELETE FROM divan_announcement_views WHERE announcement_id = ?', [$id]);
        $this->query('DELETE FROM divan_announcement_comments WHERE announcement_id = ?', [$id]);
        $this->query('DELETE FROM divan_announcements WHERE id = ?', [$id]);
    }

    public function recordAnnouncementView(int $announcementId, int $userId, ?int $now = null): void
    {
        $this->ensureAnnouncementsTables();
        $now = $now ?? time();
        $this->query('INSERT INTO divan_announcement_views (announcement_id, user_id, viewed_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE viewed_at = VALUES(viewed_at)', [$announcementId, $userId, $now]);
    }

    public function announcementViewers(int $announcementId): array
    {
        $this->ensureAnnouncementsTables();
        return $this->query('SELECT u.id, u.name, u.mobile, u.father_name, v.viewed_at FROM divan_announcement_views v INNER JOIN divan_support_users u ON u.id = v.user_id WHERE v.announcement_id = ? ORDER BY v.viewed_at DESC', [$announcementId]);
    }

    public function announcementComments(int $announcementId): array
    {
        $this->ensureAnnouncementsTables();
        return $this->query('SELECT c.id, c.announcement_id, c.user_id, c.parent_id, c.comment, c.created_at, c.updated_at, u.name AS user_name, u.mobile AS user_mobile, u.is_admin AS user_is_admin FROM divan_announcement_comments c INNER JOIN divan_support_users u ON u.id = c.user_id WHERE c.announcement_id = ? ORDER BY c.id ASC', [$announcementId]);
    }

    public function announcementCommentById(int $id): ?array
    {
        $this->ensureAnnouncementsTables();
        $rows = $this->query('SELECT id, announcement_id, user_id, parent_id, comment, created_at FROM divan_announcement_comments WHERE id = ?', [$id]);
        return $rows[0] ?? null;
    }

    public function createAnnouncementComment(int $announcementId, int $userId, ?int $parentId, string $comment, ?int $now = null): int
    {
        $this->ensureAnnouncementsTables();
        $now = $now ?? time();
        $this->query('INSERT INTO divan_announcement_comments (announcement_id, user_id, parent_id, comment, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [
            $announcementId,
            $userId,
            $parentId ?: null,
            $comment,
            $now,
            $now,
        ]);
        $rows = $this->query('SELECT LAST_INSERT_ID() as last_id');
        return (int) ($rows[0]['last_id'] ?? 0);
    }

    public function deleteAnnouncementComment(int $id): void
    {
        $this->ensureAnnouncementsTables();
        $this->query('DELETE FROM divan_announcement_comments WHERE id = ? OR parent_id = ?', [$id, $id]);
    }
}

