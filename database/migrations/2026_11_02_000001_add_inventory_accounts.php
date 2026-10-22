<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ACCOUNTS = [
        'inventory_asset' => ['1-1300', 'Persediaan Bahan', 'asset'],
        'purchase_payment' => ['1-1230', 'Kas Belanja Bahan', 'asset'],
        'cogs' => ['5-1100', 'Harga Pokok Penjualan', 'expense'],
        'waste_loss' => ['5-1200', 'Beban Bahan Terbuang', 'expense'],
        'stock_variance' => ['5-9300', 'Selisih Stok', 'expense'],
    ];

    public function up(): void
    {
        foreach (DB::table('outlets')->pluck('id') as $outletId) {
            foreach (self::ACCOUNTS as $key => [$code, $name, $type]) {
                $exists = DB::table('accounts')->where('outlet_id', $outletId)->where(fn ($q) => $q->where('code', $code)->orWhere('system_key', $key))->exists();
                if (! $exists) {
                    DB::table('accounts')->insert([
                        'outlet_id' => $outletId, 'code' => $code, 'name' => $name, 'type' => $type, 'system_key' => $key,
                        'is_system' => true, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('accounts')->whereIn('system_key', array_keys(self::ACCOUNTS))->delete();
    }
};
