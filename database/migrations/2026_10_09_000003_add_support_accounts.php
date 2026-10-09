<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('divan_support_users')) {
            Schema::create('divan_support_users', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name', 120);
                $table->string('mobile', 16)->unique();
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_unicode_ci';
            });
        }

        if (! Schema::hasTable('divan_support_otps')) {
            Schema::create('divan_support_otps', function (Blueprint $table) {
                $table->char('challenge_id', 64)->primary();
                $table->unsignedBigInteger('user_id')->index();
                $table->char('code_hash', 64);
                $table->char('source_ip_hash', 64)->index();
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->unsignedInteger('created_at')->index();
                $table->unsignedInteger('expires_at')->index();
                $table->unsignedInteger('consumed_at')->nullable();
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_unicode_ci';
            });
        }

        if (! Schema::hasTable('divan_support_tokens')) {
            Schema::create('divan_support_tokens', function (Blueprint $table) {
                $table->char('token_hash', 64)->primary();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('expires_at')->index();
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_unicode_ci';
            });
        }

        if (Schema::hasTable('divan_support_messages') &&
            ! Schema::hasColumn('divan_support_messages', 'user_id')) {
            Schema::table('divan_support_messages', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->char('receipt_hash', 64)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        throw new LogicException('Restore a verified backup; support accounts and messages are never dropped automatically.');
    }
};
