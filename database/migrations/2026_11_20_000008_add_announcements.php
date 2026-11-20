<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('divan_announcements')) {
            Schema::create('divan_announcements', function (Blueprint $table) {
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

        if (! Schema::hasTable('divan_announcement_users')) {
            Schema::create('divan_announcement_users', function (Blueprint $table) {
                $table->unsignedBigInteger('announcement_id');
                $table->unsignedBigInteger('user_id');
                $table->primary(['announcement_id', 'user_id']);
                $table->index('user_id');
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_unicode_ci';
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('divan_announcement_users');
        Schema::dropIfExists('divan_announcements');
    }
};
