<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Support\Facades\DB;

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

        return $this->query('SELECT cid, category_name, category_image, author, status FROM tbl_news_category ORDER BY cid DESC');
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

    public function supportUserByPhone($mobile)
    {
        $rows = $this->query('SELECT id, name, mobile FROM divan_support_users WHERE mobile = ?', [$mobile]);

        return $rows[0] ?? null;
    }

    public function supportUser($id)
    {
        $rows = $this->query('SELECT id, name, mobile FROM divan_support_users WHERE id = ?', [$id]);

        return $rows[0] ?? null;
    }

    public function supportUsers()
    {
        return $this->query('SELECT u.id, u.name, u.mobile, u.created_at, COUNT(m.id) AS support_messages FROM divan_support_users u LEFT JOIN divan_support_messages m ON m.user_id = u.id GROUP BY u.id, u.name, u.mobile, u.created_at ORDER BY u.id DESC LIMIT 1000');
    }

    public function saveSupportUser($name, $mobile, $now)
    {
        $this->query('INSERT INTO divan_support_users (name, mobile, created_at, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), updated_at = VALUES(updated_at)', [$name, $mobile, $now, $now]);

        return $this->supportUserByPhone($mobile);
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
        return $this->query('SELECT id, message, reply, created_at, replied_at FROM divan_support_messages WHERE user_id = ? ORDER BY id DESC LIMIT 100', [$userId]);
    }

    public function supportMessage($receiptHash)
    {
        $rows = $this->query('SELECT id, message, reply, created_at, replied_at FROM divan_support_messages WHERE receipt_hash = ?', [$receiptHash]);

        return $rows[0] ?? null;
    }

    public function supportMessages()
    {
        return $this->query('SELECT m.id, m.message, m.reply, m.created_at, m.replied_at, u.name AS user_name, u.mobile AS user_mobile FROM divan_support_messages AS m LEFT JOIN divan_support_users AS u ON u.id = m.user_id ORDER BY m.id DESC LIMIT 200');
    }

    public function supportExists($id)
    {
        return count($this->query('SELECT id FROM divan_support_messages WHERE id = ?', [$id])) === 1;
    }

    public function replyToSupport($id, $reply, $now)
    {
        $result = $this->query('UPDATE divan_support_messages SET reply = ?, replied_at = ? WHERE id = ?', [$reply, $now, $id]);

        return (int) $result['affected'];
    }

    public function deleteSupportMessage($id)
    {
        $this->query('DELETE FROM divan_support_messages WHERE id = ?', [$id]);
    }
}
