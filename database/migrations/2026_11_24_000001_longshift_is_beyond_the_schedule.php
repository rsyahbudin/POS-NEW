<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('longshift_approvals', function (Blueprint $t) {
            $t->unsignedInteger('planned_minutes')->nullable();
            $t->unsignedInteger('worked_minutes')->nullable();
        });

        // Two shifts in one day is a normal schedule, not a longshift: the planned-longshift approvals no longer exist.
        $ids = DB::table('longshift_approvals')->where('source', 'schedule')->pluck('id');
        if ($ids->isNotEmpty() && Schema::hasTable('app_notifications')) {
            DB::table('app_notifications')->whereIn('dedupe_key', $ids->map(fn ($id) => "longshift:{$id}")->all())->delete();
        }
        DB::table('longshift_approvals')->where('source', 'schedule')->delete();
    }

    public function down(): void
    {
        Schema::table('longshift_approvals', function (Blueprint $t) {
            $t->dropColumn(['planned_minutes', 'worked_minutes']);
        });
    }
};
