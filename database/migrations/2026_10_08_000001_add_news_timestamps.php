<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tbl_news')) {
            return;
        }

        Schema::table('tbl_news', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_news', 'created_at')) {
                // Legacy rows have no known creation date; keep it NULL rather than invent one.
                $table->unsignedInteger('created_at')->nullable();
            }
            if (! Schema::hasColumn('tbl_news', 'updated_at')) {
                // This becomes the real edit time on the next panel save.
                $table->unsignedInteger('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        throw new LogicException('Restore a verified backup; article timestamps are never dropped automatically.');
    }
};
