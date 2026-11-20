<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('divan_support_users')) {
            Schema::table('divan_support_users', function (Blueprint $table) {
                if (! Schema::hasColumn('divan_support_users', 'father_name')) {
                    $table->string('father_name', 120)->nullable()->after('name');
                }
                if (! Schema::hasColumn('divan_support_users', 'is_admin')) {
                    $table->boolean('is_admin')->default(false)->after('mobile');
                }
            });
        }

        if (! Schema::hasTable('divan_announcement_views')) {
            Schema::create('divan_announcement_views', function (Blueprint $table) {
                $table->unsignedBigInteger('announcement_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedInteger('viewed_at');
                $table->primary(['announcement_id', 'user_id']);
                $table->index('user_id');
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_unicode_ci';
            });
        }

        if (! Schema::hasTable('divan_announcement_comments')) {
            Schema::create('divan_announcement_comments', function (Blueprint $table) {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('divan_announcement_comments');
        Schema::dropIfExists('divan_announcement_views');
        if (Schema::hasTable('divan_support_users')) {
            Schema::table('divan_support_users', function (Blueprint $table) {
                if (Schema::hasColumn('divan_support_users', 'father_name')) {
                    $table->dropColumn('father_name');
                }
                if (Schema::hasColumn('divan_support_users', 'is_admin')) {
                    $table->dropColumn('is_admin');
                }
            });
        }
    }
};
