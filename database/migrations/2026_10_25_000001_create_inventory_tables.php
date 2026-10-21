<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('unit', 12);
            $t->decimal('stock', 14, 3)->default(0);
            $t->decimal('avg_cost', 14, 4)->default(0); // rupiah per unit, moving average
            $t->decimal('min_stock', 14, 3)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['outlet_id', 'name']);
        });

        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $t->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $t->string('type', 12); // purchase | sale | void_return | waste | opname
            $t->decimal('qty', 14, 3); // signed: + in, - out
            $t->decimal('unit_cost', 14, 4)->default(0);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('order_item_id')->nullable()->index();
            $t->unsignedBigInteger('purchase_id')->nullable();
            $t->string('note', 200)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['ingredient_id', 'created_at']);
        });

        Schema::create('recipe_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete(); // null = every variant
            $t->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $t->decimal('qty', 14, 4);
        });

        Schema::create('modifier_ingredients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('modifier_id')->constrained()->cascadeOnDelete();
            $t->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $t->decimal('qty', 14, 4);
        });

        Schema::create('purchases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $t->string('supplier', 80)->nullable();
            $t->date('purchased_on');
            $t->string('note', 200)->nullable();
            $t->bigInteger('total')->default(0);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('purchase_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $t->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $t->decimal('qty', 14, 3);
            $t->decimal('unit_cost', 14, 4);
            $t->bigInteger('total');
        });

        Schema::table('order_items', function (Blueprint $t) {
            $t->unsignedBigInteger('variant_id')->nullable();
            $t->jsonb('modifier_ids')->nullable();
            $t->bigInteger('cogs')->default(0); // ingredient cost of this line, fixed at sale time
            $t->boolean('inventory_consumed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $t) {
            $t->dropColumn(['variant_id', 'modifier_ids', 'cogs', 'inventory_consumed']);
        });
        foreach (['purchase_items', 'purchases', 'modifier_ingredients', 'recipe_lines', 'stock_movements', 'ingredients'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
