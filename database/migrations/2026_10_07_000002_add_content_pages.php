<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('divan_pages')) {
            Schema::create('divan_pages', function (Blueprint $table) {
                $table->string('slug', 40)->primary();
                $table->string('title', 255);
                $table->longText('html_body');
                $table->unsignedInteger('revision')->default(1);
                $table->unsignedInteger('updated_at');
            });
        }
        // Import the supplied APK's text once; never replace panel edits.
        $pages = json_decode(file_get_contents(__DIR__.'/../data/content_pages.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($pages as $page) {
            DB::table('divan_pages')->insertOrIgnore($page + ['revision' => 1, 'updated_at' => time()]);
        }
    }

    public function down(): void
    {
        throw new LogicException('Restore a verified backup; published pages are never dropped automatically.');
    }
};
