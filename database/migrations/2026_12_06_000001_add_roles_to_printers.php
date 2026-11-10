<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** One physical printer can print several things (receipt, bill, tickets): it is registered once with its roles. */
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->jsonb('roles')->nullable()->after('kind');
        });

        foreach (DB::table('printers')->get(['id', 'kind']) as $p) {
            DB::table('printers')->where('id', $p->id)->update(['roles' => json_encode([$p->kind])]);
        }
    }

    public function down(): void
    {
        Schema::table('printers', fn (Blueprint $table) => $table->dropColumn('roles'));
    }
};
