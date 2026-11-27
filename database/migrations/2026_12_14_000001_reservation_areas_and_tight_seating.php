<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A table or gazebo has a normal capacity and an optional maximum for a group that squeezes in. A booking names the
     * area the guest chose and its tables are held when it is made.
     */
    public function up(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_capacity')->nullable()->after('capacity');
        });
        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable()->after('outlet_id')->constrained('areas')->nullOnDelete();
            $table->boolean('tight')->default(false)->after('full_menu');   // seated beyond the normal capacity
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('area_id');
            $table->dropColumn('tight');
        });
        Schema::table('tables', fn (Blueprint $table) => $table->dropColumn('max_capacity'));
    }
};
