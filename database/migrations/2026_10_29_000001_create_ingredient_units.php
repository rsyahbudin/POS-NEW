<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Packaging an ingredient is bought in, e.g. "botol" = 750 ml. Stock and recipes always use the base unit.
        Schema::create('ingredient_units', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $t->string('name', 20);
            $t->decimal('factor', 14, 4); // base units in one of this packaging
            $t->unique(['ingredient_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_units');
    }
};
