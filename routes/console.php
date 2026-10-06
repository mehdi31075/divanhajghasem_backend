<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Artisan::command('divan:check', function () {
    $expected = [
        'tbl_user' => ['ID', 'Username', 'Password', 'Email'],
        'tbl_news_category' => ['cid', 'category_name', 'category_image', 'author', 'status'],
        'tbl_news' => ['nid', 'news_heading', 'cat_id', 'news_status', 'news_date', 'news_image', 'news_description'],
        'divan_api_tokens' => ['token_hash', 'username', 'password_digest', 'created_at', 'expires_at'],
        'divan_api_login_attempts' => ['ip_hash', 'attempted_at'],
    ];
    foreach ($expected as $table => $columns) {
        if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
            $this->error('Missing table/columns: '.$table);

            return 1;
        }
    }
    foreach (['public/upload/category', 'storage', 'bootstrap/cache'] as $directory) {
        if (! is_writable(base_path($directory))) {
            $this->error('Not writable: '.$directory);

            return 1;
        }
    }
    $this->info('Schema and writable directories are ready. No production data changed.');

    return 0;
})->purpose('Check legacy schema and deployment paths without changing data');

Artisan::command('divan:create-admin {username} {email}', function () {
    $username = strtolower(trim($this->argument('username')));
    $email = $this->argument('email');
    if (! preg_match('/^[a-z0-9_.-]{1,15}$/', $username) || strlen($email) > 100 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Invalid username or email.');

        return 1;
    }
    if (DB::table('tbl_user')->where('Username', $username)->exists()) {
        $this->error('Account already exists; it was not changed.');

        return 1;
    }
    $password = $this->secret('Password (at least 8 characters)');
    if (! is_string($password) || strlen($password) < 8 || $password !== $this->secret('Confirm password')) {
        $this->error('Password is too short or confirmation does not match.');

        return 1;
    }
    DB::table('tbl_user')->insert(['Username' => $username, 'Email' => $email, 'Password' => password_hash($password, PASSWORD_BCRYPT)]);
    $this->info('Administrator created.');

    return 0;
})->purpose('Create an administrator on a fresh database; never seed a default password');
