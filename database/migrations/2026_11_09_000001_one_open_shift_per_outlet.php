<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The shift belongs to the store, not to a person: at most one is open per outlet at a time.
     * If old data still has several open shifts in one outlet, only the app-level rule applies until they are closed.
     */
    public function up(): void
    {
        $crowded = DB::selectOne('select 1 as x from shifts where closed_at is null group by outlet_id having count(*) > 1 limit 1') !== null;
        if (! $crowded) {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS shifts_one_open_per_outlet ON shifts (outlet_id) WHERE closed_at IS NULL');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS shifts_one_open_per_outlet');
    }
};
