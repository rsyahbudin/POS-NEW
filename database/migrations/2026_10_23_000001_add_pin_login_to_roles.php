<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Which roles may sign in with a personal PIN is a property of the role (Peran & Akses). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('allow_pin_login')->default(false);
        });

        DB::table('roles')->whereIn('name', ['cashier', 'waiter', 'kitchen'])->update(['allow_pin_login' => true]);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('allow_pin_login');
        });
    }
};
