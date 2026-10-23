<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_presets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $t->string('name', 60);
            $t->string('type', 8); // percent (basis points) | fixed (rupiah)
            $t->unsignedBigInteger('value');
            $t->boolean('needs_pin')->default(false);
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['outlet_id', 'name']);
        });

        Schema::table('discounts', function (Blueprint $t) {
            $t->foreignId('preset_id')->nullable()->after('promotion_id')->constrained('discount_presets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('discounts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('preset_id');
        });
        Schema::dropIfExists('discount_presets');
    }
};
