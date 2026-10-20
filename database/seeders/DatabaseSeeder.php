<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Modifier;
use App\Domain\Catalog\Models\ModifierGroup;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Station;
use App\Domain\Floor\Models\Area;
use App\Domain\Floor\Models\Table;
use App\Domain\Settings\Actions\SetupOutletAction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $outlet = app(SetupOutletAction::class)->handle('Kafe Contoh', 'Jl. Contoh No. 1, Jakarta');

        foreach ([
            ['owner', 'Owner', 'owner@pos.test', '111111'],
            ['manager', 'Manager', 'manager@pos.test', '222222'],
            ['cashier', 'Kasir', 'kasir@pos.test', '333333'],
            ['waiter', 'Waiter', 'waiter@pos.test', null],
            ['kitchen', 'Dapur', 'dapur@pos.test', null],
        ] as [$role, $name, $email, $pin]) {
            $user = User::firstOrCreate(['email' => $email], [
                'name' => $name, 'password' => Hash::make('password'), 'outlet_id' => $outlet->id,
                'pin_hash' => $pin ? Hash::make($pin) : null, 'email_verified_at' => now(),
            ]);
            $user->syncRoles([$role]);
        }

        if (Product::query()->exists()) {
            return;
        }

        $station = Station::query()->firstOrFail();
        $coffee = Category::create(['outlet_id' => $outlet->id, 'name' => 'Kopi', 'sort' => 1]);
        $food = Category::create(['outlet_id' => $outlet->id, 'name' => 'Makanan', 'sort' => 2]);

        $sugar = ModifierGroup::create(['outlet_id' => $outlet->id, 'name' => 'Sugar Level', 'min_select' => 1, 'max_select' => 1, 'is_required' => true]);
        foreach (['Normal', 'Less Sugar', 'No Sugar'] as $i => $n) {
            Modifier::create(['modifier_group_id' => $sugar->id, 'name' => $n, 'price_delta' => 0, 'sort' => $i]);
        }
        $extra = ModifierGroup::create(['outlet_id' => $outlet->id, 'name' => 'Tambahan', 'min_select' => 0, 'max_select' => 2, 'is_required' => false]);
        Modifier::create(['modifier_group_id' => $extra->id, 'name' => 'Extra Shot', 'price_delta' => 5000]);
        Modifier::create(['modifier_group_id' => $extra->id, 'name' => 'Oat Milk', 'price_delta' => 8000]);

        $latte = Product::create(['outlet_id' => $outlet->id, 'category_id' => $coffee->id, 'station_id' => $station->id, 'name' => 'Caffe Latte', 'sku' => 'KP-001', 'base_price' => 28000, 'sort' => 1]);
        $latte->variants()->createMany([['name' => 'Hot', 'price' => 28000, 'sort' => 1], ['name' => 'Iced', 'price' => 30000, 'sort' => 2]]);
        $latte->modifierGroups()->attach([$sugar->id, $extra->id]);

        Product::create(['outlet_id' => $outlet->id, 'category_id' => $coffee->id, 'station_id' => $station->id, 'name' => 'Americano', 'sku' => 'KP-002', 'base_price' => 22000, 'sort' => 2])
            ->modifierGroups()->attach([$extra->id]);
        Product::create(['outlet_id' => $outlet->id, 'category_id' => $food->id, 'station_id' => $station->id, 'name' => 'Croissant', 'sku' => 'MK-001', 'base_price' => 25000, 'stock_qty' => 20, 'sort' => 1]);
        Product::create(['outlet_id' => $outlet->id, 'category_id' => $food->id, 'station_id' => $station->id, 'name' => 'Nasi Goreng', 'sku' => 'MK-002', 'base_price' => 38000, 'sort' => 2]);

        $indoor = Area::create(['outlet_id' => $outlet->id, 'name' => 'Indoor', 'sort' => 1]);
        $outdoor = Area::create(['outlet_id' => $outlet->id, 'name' => 'Outdoor', 'sort' => 2]);
        foreach (range(1, 6) as $i) {
            Table::create(['outlet_id' => $outlet->id, 'area_id' => $indoor->id, 'name' => "I-{$i}", 'capacity' => 4]);
        }
        foreach (range(1, 4) as $i) {
            Table::create(['outlet_id' => $outlet->id, 'area_id' => $outdoor->id, 'name' => "O-{$i}", 'capacity' => 2]);
        }
    }
}
