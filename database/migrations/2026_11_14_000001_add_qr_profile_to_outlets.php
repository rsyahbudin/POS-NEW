<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $t) {
            $t->string('banner_path')->nullable();
            $t->string('tagline', 120)->nullable();
            $t->string('opening_hours', 160)->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('instagram', 60)->nullable();
            $t->string('wifi_name', 60)->nullable();
            $t->string('wifi_password', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $t) {
            $t->dropColumn(['banner_path', 'tagline', 'opening_hours', 'phone', 'instagram', 'wifi_name', 'wifi_password']);
        });
    }
};
