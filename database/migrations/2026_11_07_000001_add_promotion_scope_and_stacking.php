<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $t) {
            $t->string('scope', 10)->default('order')->after('value'); // order | category | product
            $t->json('category_ids')->nullable()->after('scope');
            $t->json('product_ids')->nullable()->after('category_ids');
            // Existing promos behaved as "only one promo per order", so they stay exclusive.
            $t->boolean('combinable')->default(false)->after('product_ids');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $t) {
            $t->dropColumn(['scope', 'category_ids', 'product_ids', 'combinable']);
        });
    }
};
