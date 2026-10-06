<?php

use App\Exceptions\ApiError;
use App\Services\DivanApi;
use App\Services\DivanRepository;

require_once __DIR__.'/../vendor/autoload.php';
class_alias(DivanRepository::class, 'DivanMySqlStore');
class_alias(DivanApi::class, 'DivanMobileApi');
class_alias(ApiError::class, 'DivanApiError');
// Execute the production store's SQL against an isolated SQLite fixture.
// Only the low-level mysqli binding is substituted; no web server is started.
class FixtureStore extends DivanMySqlStore
{
    protected $db;

    public function __construct($dsn = 'sqlite::memory:')
    {
        $this->db = new PDO($dsn);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE tbl_user (Username TEXT, Password TEXT, Email TEXT);
            CREATE TABLE tbl_news_category (cid INTEGER PRIMARY KEY, category_name TEXT, category_image TEXT, author TEXT DEFAULT '', status INT DEFAULT 1);
            CREATE TABLE tbl_news (nid INTEGER PRIMARY KEY AUTOINCREMENT, news_heading TEXT NOT NULL, cat_id INTEGER NOT NULL, news_status INTEGER DEFAULT 1, news_date TEXT NOT NULL, news_image TEXT NOT NULL, news_description TEXT NOT NULL);
            CREATE TABLE divan_api_tokens (token_hash TEXT PRIMARY KEY, username TEXT, password_digest TEXT, created_at INT, expires_at INT);
            CREATE TABLE divan_api_login_attempts (id INTEGER PRIMARY KEY, ip_hash TEXT, attempted_at INT);");
        $this->query('INSERT INTO tbl_user (Username, Password) VALUES (?, ?)', ['fixture-admin', hash('sha256', 'fixture-admin'.'fixture-password')]);
        $this->db->exec("INSERT INTO tbl_news_category (cid, category_name, category_image) VALUES (61, 'دسته', 'image.png'), (62, 'دسته دوم', 'image2.png')");
    }

    protected function query($sql, $params = [])
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return strpos($sql, 'SELECT') === 0 ? $stmt->fetchAll(PDO::FETCH_ASSOC) :
            ['affected' => $stmt->rowCount(), 'id' => $this->db->lastInsertId()];
    }

    public function rows($sql)
    {
        return $this->query($sql);
    }

    public function exec($sql)
    {
        $this->db->exec($sql);
    }
}
