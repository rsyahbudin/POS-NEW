<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $t) {
            $t->index(['outlet_id', 'created_at']);
        });
        Schema::table('notification_reads', function (Blueprint $t) {
            $t->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('app_notifications', function (Blueprint $t) {
            $t->dropIndex(['outlet_id', 'created_at']);
        });
        Schema::table('notification_reads', function (Blueprint $t) {
            $t->dropIndex(['user_id']);
        });
    }
};
