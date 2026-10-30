<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $t) {
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
        });
        Schema::table('attendances', function (Blueprint $t) {
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->unsignedInteger('geo_distance_m')->nullable();
            $t->unsignedInteger('out_geo_distance_m')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $t) {
            $t->dropColumn(['lat', 'lng', 'geo_distance_m', 'out_geo_distance_m']);
        });
        Schema::table('outlets', function (Blueprint $t) {
            $t->dropColumn(['latitude', 'longitude']);
        });
    }
};
