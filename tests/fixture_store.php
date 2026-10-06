<?php

use App\Exceptions\ApiError;
use App\Services\DivanApi;
use App\Services\DivanRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\DatabaseSafety;

require_once __DIR__.'/../vendor/autoload.php';
class_alias(DivanRepository::class, 'DivanMySqlStore');
class_alias(DivanApi::class, 'DivanMobileApi');
class_alias(ApiError::class, 'DivanApiError');

// Run the actual production repository against an explicitly isolated database.
class FixtureStore extends DivanMySqlStore
{
    public function __construct()
    {
        if (! is_file(__DIR__.'/../.env.testing')) {
            throw new LogicException('Create .env.testing from .env.testing.example with a dedicated MySQL/MariaDB test database first.');
        }
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->loadEnvironmentFrom('.env.testing');
        $app->make(Kernel::class)->bootstrap();
        DatabaseSafety::assertReady($app);
        // Never reached unless the effective database is explicitly opted in,
        // belongs to the testing environment, and ends in _test.
        if (Artisan::call('migrate:fresh', ['--force' => true]) !== 0) {
            throw new RuntimeException('Failed to prepare the isolated test database.');
        }
        $this->query('INSERT INTO tbl_user (Username, Password, Email) VALUES (?, ?, ?)', ['fixture-admin', hash('sha256', 'fixture-adminfixture-password'), 'admin@example.test']);
        DB::table('tbl_news_category')->insert([
            ['cid' => 61, 'category_name' => 'دسته', 'category_image' => 'image.png', 'author' => '', 'status' => 1],
            ['cid' => 62, 'category_name' => 'دسته دوم', 'category_image' => 'image2.png', 'author' => '', 'status' => 1],
        ]);
    }

    public function rows($sql)
    {
        return $this->query($sql);
    }

    public function exec($sql)
    {
        DB::unprepared($sql);
    }
}
