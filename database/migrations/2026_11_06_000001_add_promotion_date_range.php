<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $t) {
            $t->date('starts_on')->nullable()->after('days');
            $t->date('ends_on')->nullable()->after('starts_on');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $t) {
            $t->dropColumn(['starts_on', 'ends_on']);
        });
    }
};
