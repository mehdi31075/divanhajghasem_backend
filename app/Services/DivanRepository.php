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
        $rows = $this->query('SELECT nid, news_heading, cat_id, news_date FROM tbl_news'.$filter.' ORDER BY nid DESC LIMIT 50 OFFSET '.(($page - 1) * 50), $params);

        return ['posts' => $rows, 'total' => (int) $total[0]['total'], 'page' => $page];
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
        $sql = 'SELECT c.cid, c.category_name, c.category_image, c.author, c.status, n.nid, n.news_heading, n.cat_id, n.news_status, n.news_date, n.news_image, n.news_description FROM tbl_news_category c JOIN tbl_news n ON c.cid = n.cat_id';
        if (isset($query['cat_id'])) {
            return $this->query($sql.' WHERE c.cid = ? ORDER BY n.nid ASC', [$query['cat_id']]);
        }
        if (isset($query['nid'])) {
            return $this->query($sql.' WHERE n.nid = ?', [$query['nid']]);
        }
        if (isset($query['latest_news'])) {
            return $this->query($sql.' ORDER BY n.nid ASC LIMIT '.(int) $query['latest_news']);
        }

        return $this->query('SELECT cid, category_name, category_image, author, status FROM tbl_news_category ORDER BY cid DESC');
    }

    public function createArticle($input)
    {
        // INSERT...SELECT locks the category for this statement on MyISAM too.
        $result = $this->query("INSERT INTO tbl_news (news_heading, cat_id, news_date, news_description, news_image) SELECT ?, cid, ?, ?, '' FROM tbl_news_category WHERE cid = ?", [$input['news_heading'], $input['news_date'], $input['news_description'], $input['cid']]);
        if (! (int) $result['affected']) {
            throw new ApiError(409, 'category_changed', 'دسته پیش از ذخیره حذف شده است.');
        }

        return (string) $result['id'];
    }

    public function updateArticle($id, $input)
    {
        $result = $this->query('UPDATE tbl_news SET news_heading = ?, cat_id = ?, news_date = ?, news_description = ? WHERE nid = ? AND EXISTS (SELECT 1 FROM tbl_news_category WHERE cid = ?)', [$input['news_heading'], $input['cid'], $input['news_date'], $input['news_description'], $id, $input['cid']]);
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
    }
}
