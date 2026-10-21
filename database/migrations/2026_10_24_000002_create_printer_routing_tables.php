<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which ticket printers a category (default) or a single menu item prints on. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_printer', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('printer_id')->constrained()->cascadeOnDelete();
            $table->primary(['category_id', 'printer_id']);
        });

        Schema::create('product_printer', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('printer_id')->constrained()->cascadeOnDelete();
            $table->primary(['product_id', 'printer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_printer');
        Schema::dropIfExists('category_printer');
    }
};
