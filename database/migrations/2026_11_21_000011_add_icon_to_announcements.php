<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('divan_announcements')) {
            if (! Schema::hasColumn('divan_announcements', 'icon')) {
                Schema::table('divan_announcements', function (Blueprint $table) {
                    $table->string('icon', 50)->default('campaign')->after('is_public');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('divan_announcements')) {
            if (Schema::hasColumn('divan_announcements', 'icon')) {
                Schema::table('divan_announcements', function (Blueprint $table) {
                    $table->dropColumn('icon');
                });
            }
        }
    }
};
