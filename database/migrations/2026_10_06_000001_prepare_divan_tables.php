<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing CMS tables are reused verbatim. Never replace their rows/accounts.
        if (! Schema::hasTable('tbl_user')) {
            Schema::create('tbl_user', function (Blueprint $table) {
                $table->increments('ID');
                $table->string('Username', 15)->unique();
                $table->text('Password');
                $table->string('Email', 100)->default('');
            });
        }
        if (! Schema::hasTable('tbl_news_category')) {
            Schema::create('tbl_news_category', function (Blueprint $table) {
                $table->increments('cid');
                $table->string('category_name', 255);
                $table->text('category_image');
                $table->string('author', 50);
                $table->integer('status')->default(1);
            });
        }
        if (! Schema::hasTable('tbl_news')) {
            Schema::create('tbl_news', function (Blueprint $table) {
                $table->increments('nid');
                $table->string('news_heading', 500);
                $table->integer('cat_id')->index();
                $table->integer('news_status')->default(1);
                $table->string('news_date', 255);
                $table->text('news_image');
                $table->longText('news_description');
            });
        }
        if (! Schema::hasTable('divan_api_tokens')) {
            Schema::create('divan_api_tokens', function (Blueprint $table) {
                $table->char('token_hash', 64)->primary();
                $table->string('username', 255);
                $table->char('password_digest', 64);
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('expires_at')->index();
            });
        }
        if (! Schema::hasTable('divan_api_login_attempts')) {
            Schema::create('divan_api_login_attempts', function (Blueprint $table) {
                $table->id();
                $table->char('ip_hash', 64);
                $table->unsignedInteger('attempted_at')->index();
                $table->index(['ip_hash', 'attempted_at']);
            });
        }
    }

    public function down(): void
    {
        // These tables may predate Laravel and contain production data.
        throw new LogicException('Restore a verified backup to roll back; Divan tables are never dropped automatically.');
    }
};
