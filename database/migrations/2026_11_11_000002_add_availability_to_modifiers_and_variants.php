<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['modifiers', 'product_variants'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->boolean('is_available')->default(true);
            });
        }
    }

    public function down(): void
    {
        foreach (['modifiers', 'product_variants'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('is_available'));
        }
    }
};
