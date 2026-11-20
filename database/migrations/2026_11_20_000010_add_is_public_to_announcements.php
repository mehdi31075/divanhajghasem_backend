<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('divan_announcements')) {
            if (! Schema::hasColumn('divan_announcements', 'is_public')) {
                Schema::table('divan_announcements', function (Blueprint $table) {
                    $table->boolean('is_public')->default(false)->index()->after('description');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('divan_announcements')) {
            if (Schema::hasColumn('divan_announcements', 'is_public')) {
                Schema::table('divan_announcements', function (Blueprint $table) {
                    $table->dropColumn('is_public');
                });
            }
        }
    }
};
