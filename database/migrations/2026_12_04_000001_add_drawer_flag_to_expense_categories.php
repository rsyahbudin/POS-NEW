<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Which expense categories staff may pay out of the cash drawer (supplies yes, salary and rent no). */
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->boolean('allow_drawer')->default(false)->after('account_id');
        });

        DB::table('expense_categories')->whereIn(DB::raw('lower(name)'), ['bahan baku', 'lain-lain'])->update(['allow_drawer' => true]);
    }

    public function down(): void
    {
        Schema::table('expense_categories', fn (Blueprint $table) => $table->dropColumn('allow_drawer'));
    }
};
