<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The kitchen or bar screen a whole category shows on, so a menu item no longer needs a station of its own. */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('station_id')->nullable()->after('name')->constrained('stations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('station_id');
        });
    }
};
