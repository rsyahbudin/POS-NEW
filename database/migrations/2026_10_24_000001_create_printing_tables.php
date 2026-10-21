<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('kind', 12); // receipt | bill | ticket
            $table->foreignId('station_id')->nullable()->constrained('stations')->nullOnDelete();
            $table->unsignedSmallInteger('paper_width')->default(58);
            $table->unsignedSmallInteger('copies')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('printer_id')->constrained('printers')->cascadeOnDelete();
            $table->string('kind', 12);
            $table->string('reference', 40)->nullable();
            $table->jsonb('payload');
            $table->string('status', 10)->default('pending'); // pending | printed | failed
            $table->string('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();
            $table->index(['printer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('printers');
    }
};
