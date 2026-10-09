<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('divan_support_replies')) {
            Schema::create('divan_support_replies', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('ticket_id')->index();
                $table->string('sender', 16)->index();
                $table->text('message');
                $table->unsignedInteger('created_at')->index();
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_unicode_ci';
            });
        }
    }

    public function down(): void
    {
        throw new LogicException('Restore a verified backup; support conversations are never dropped automatically.');
    }
};
