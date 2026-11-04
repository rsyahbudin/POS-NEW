<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** For cafes whose WiFi asks for a username and a password (a login page), not just a password. */
    public function up(): void
    {
        Schema::table('outlets', fn (Blueprint $t) => $t->string('wifi_username', 60)->nullable()->after('wifi_name'));
    }

    public function down(): void
    {
        Schema::table('outlets', fn (Blueprint $t) => $t->dropColumn('wifi_username'));
    }
};
