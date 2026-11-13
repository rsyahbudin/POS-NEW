<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Outlet-curated favorite menus (QR + kasir) and default modifier options. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->boolean('is_favorite')->default(false)->after('is_bundle');
        });
        Schema::table('modifiers', function (Blueprint $t) {
            $t->boolean('is_default')->default(false)->after('price_delta');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('is_favorite'));
        Schema::table('modifiers', fn (Blueprint $t) => $t->dropColumn('is_default'));
    }
};
