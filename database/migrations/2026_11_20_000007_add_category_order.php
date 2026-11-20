<?php

use App\Services\DivanRepository;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        (new DivanRepository)->ensureCategoryOrderTable();
    }

    public function down(): void
    {
        Schema::dropIfExists('divan_category_order');
    }
};
