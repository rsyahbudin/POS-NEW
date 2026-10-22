<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const DEFAULT_REASONS = ['Basi / busuk', 'Kadaluarsa', 'Tumpah', 'Rusak / pecah', 'Salah buat', 'Lainnya'];

    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('phone', 30)->nullable();
            $t->string('address', 200)->nullable();
            $t->string('note', 300)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['outlet_id', 'name']);
        });

        Schema::table('purchases', function (Blueprint $t) {
            $t->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
        });
        Schema::table('ingredients', function (Blueprint $t) {
            $t->foreignId('preferred_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
        });

        Schema::create('waste_reasons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $t->string('name', 40);
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->unique(['outlet_id', 'name']);
        });

        // One row per thing thrown away (an ingredient, or finished menu portions); cost is fixed at that moment.
        Schema::create('waste_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('kind', 10); // ingredient | product
            $t->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->string('label', 120);
            $t->decimal('qty', 14, 3);
            $t->string('unit', 12);
            $t->string('reason', 40);
            $t->string('note', 200)->nullable();
            $t->bigInteger('cost')->default(0);
            $t->timestamp('created_at')->useCurrent();
            $t->index(['outlet_id', 'created_at']);
        });

        Schema::table('stock_movements', function (Blueprint $t) {
            $t->unsignedBigInteger('waste_entry_id')->nullable()->index();
        });

        foreach (DB::table('outlets')->pluck('id') as $outletId) {
            foreach (self::DEFAULT_REASONS as $i => $name) {
                DB::table('waste_reasons')->insertOrIgnore(['outlet_id' => $outletId, 'name' => $name, 'sort' => $i]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('stock_movements', fn (Blueprint $t) => $t->dropColumn('waste_entry_id'));
        Schema::dropIfExists('waste_entries');
        Schema::dropIfExists('waste_reasons');
        Schema::table('ingredients', fn (Blueprint $t) => $t->dropConstrainedForeignId('preferred_supplier_id'));
        Schema::table('purchases', fn (Blueprint $t) => $t->dropConstrainedForeignId('supplier_id'));
        Schema::dropIfExists('suppliers');
    }
};
