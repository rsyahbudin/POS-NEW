<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 10)->default('draft'); // draft | submitted | approved | rejected
            $t->string('note', 200)->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('review_note', 200)->nullable();
            $t->timestamps();
            $t->index(['outlet_id', 'status']);
        });

        Schema::create('stock_count_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $t->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $t->decimal('counted', 14, 3);
            $t->decimal('system_qty', 14, 3)->nullable(); // stock at submit time
            $t->unique(['stock_count_id', 'ingredient_id']);
        });

        Schema::create('assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);
            $t->string('name', 100);
            $t->string('category', 40)->nullable();
            $t->string('brand', 60)->nullable();
            $t->string('model', 60)->nullable();
            $t->string('serial', 60)->nullable();
            $t->unsignedInteger('quantity')->default(1);
            $t->date('purchased_on')->nullable();
            $t->bigInteger('purchase_price')->default(0); // per unit, rupiah
            $t->string('location', 60)->nullable();
            $t->string('condition', 14)->default('good'); // good | needs_service | broken | retired
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['outlet_id', 'code']);
        });

        Schema::create('asset_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $t->string('type', 12); // service | repair | condition | move | note
            $t->date('happened_on');
            $t->bigInteger('cost')->default(0);
            $t->string('note', 200)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['asset_logs', 'assets', 'stock_count_lines', 'stock_counts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
