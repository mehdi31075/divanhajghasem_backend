<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('divan_media_names')) {
            Schema::create('divan_media_names', function (Blueprint $table) {
                $table->string('filename', 255)->primary();
                $table->string('display_name', 120);
                $table->unsignedInteger('updated_at');
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_unicode_ci';
            });
        }
        if (! Schema::hasTable('divan_support_messages')) {
            Schema::create('divan_support_messages', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->char('receipt_hash', 64)->unique();
                $table->char('source_ip_hash', 64)->index();
                $table->text('message');
                $table->text('reply')->nullable();
                $table->unsignedInteger('created_at')->index();
                $table->unsignedInteger('replied_at')->nullable();
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_unicode_ci';
            });
        }
    }

    public function down(): void
    {
        throw new LogicException('Restore a verified backup; media labels and support messages are never dropped automatically.');
    }
};
