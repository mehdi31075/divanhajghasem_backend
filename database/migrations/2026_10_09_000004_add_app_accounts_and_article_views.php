<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('divan_support_otps')) {
            if (! Schema::hasColumn('divan_support_otps', 'mobile')) {
                Schema::table('divan_support_otps', function (Blueprint $table) {
                    $table->string('mobile', 16)->nullable()->after('user_id');
                });
            }
            Schema::table('divan_support_otps', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->change();
            });
        }

        if (! Schema::hasTable('divan_post_views')) {
            Schema::create('divan_post_views', function (Blueprint $table) {
                $table->unsignedBigInteger('post_id')->primary();
                $table->unsignedBigInteger('views')->default(0);
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_unicode_ci';
            });
        }
    }

    public function down(): void
    {
        throw new LogicException('Restore a verified backup; app accounts and article view counts are preserved.');
    }
};
