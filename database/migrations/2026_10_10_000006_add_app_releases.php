<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('divan_app_releases')) {
            Schema::create('divan_app_releases', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('version', 50);
                $table->unsignedInteger('build_number')->default(1);
                $table->string('filename', 255);
                $table->string('file_path', 500);
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->text('changelog')->nullable();
                $table->boolean('is_latest')->default(false)->index();
                $table->unsignedInteger('created_at')->index();
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_unicode_ci';
            });
        }
    }

    public function down(): void
    {
        // Safe down
    }
};
