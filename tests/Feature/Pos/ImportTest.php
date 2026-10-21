<?php

use App\Domain\Asset\Models\Asset;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Import\ImportNumber;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\Purchase;
use App\Domain\Inventory\Models\RecipeLine;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Settings\Models\Outlet;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function sheetFile(array $matrix): UploadedFile
{
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet()->setTitle('Data');
    foreach ($matrix as $r => $row) {
        foreach (array_values($row) as $c => $value) {
            $sheet->setCellValueExplicit([$c + 1, $r + 1], (string) $value, DataType::TYPE_STRING); // typed text, like a person would
        }
    }
    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    (new Xlsx($book))->save($path);

    return new UploadedFile($path, 'impor.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function ing(string $name, string $unit = 'g', float $stock = 0, float $cost = 0): Ingredient
{
    return Ingredient::create(['outlet_id' => (int) Outlet::query()->value('id'), 'name' => $name, 'unit' => $unit, 'stock' => $stock, 'avg_cost' => $cost]);
}

function previewOf($test, string $type, UploadedFile $file, $user)
{
    return $test->actingAs($user)->postJson("/import/{$type}/preview", ['file' => $file]);
}

// ---------------------------------------------------------------- reading numbers

it('reads numbers the way people type them', function () {
    expect(ImportNumber::parse('1.500'))->toBe(1500.0)->and(ImportNumber::parse('1,5'))->toBe(1.5)->and(ImportNumber::parse('Rp 15.000'))->toBe(15000.0)
        ->and(ImportNumber::parse('1.234,56'))->toBe(1234.56)->and(ImportNumber::parse('1,234.56'))->toBe(1234.56)->and(ImportNumber::parse('2.5'))->toBe(2.5)
        ->and(ImportNumber::parse('1.500.000'))->toBe(1500000.0)->and(ImportNumber::parse(12))->toBe(12.0)->and(ImportNumber::parse('abc'))->toBeNull()->and(ImportNumber::parse(''))->toBeNull();
});

// ---------------------------------------------------------------- templates

it('serves templates prefilled with what exists, with a drop-down list and instructions', function () {
    ing('Biji kopi', 'g', 100, 200);
    $manager = userWithRole('manager');

    foreach (['ingredients', 'purchase', 'count', 'recipes', 'products', 'assets'] as $type) {
        $res = $this->actingAs($manager)->get("/import/{$type}/template");
        $res->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $res->streamedContent());
        $book = IOFactory::load($path);
        expect($book->getSheetNames())->toContain('Data', 'Petunjuk');
        expect($book->getSheetByName('Data')->getCell('A1')->getValue())->toBeString();
    }

    $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
    file_put_contents($path, $this->actingAs($manager)->get('/import/purchase/template')->streamedContent());
    $data = IOFactory::load($path)->getSheetByName('Data');
    expect($data->getCell('A2')->getValue())->toBe('Biji kopi')->and($data->getCell('B2')->getValue())->toBe('g');
    expect(IOFactory::load($path)->getSheetNames())->toContain('Daftar');
});

// ---------------------------------------------------------------- ingredients

it('imports ingredients: adds new ones with opening stock, updates existing ones, and flags bad rows', function () {
    ing('Gula', 'g', 50, 10);
    $manager = userWithRole('manager');
    $file = sheetFile([
        ['Bahan *', 'Satuan *', 'Batas menipis', 'Stok awal', 'Harga per satuan'],
        ['Gula', 'kg', '200', '', ''],           // update unit + minimum
        ['Susu', 'ml', '500', '8.000', '20'],    // new
        ['Kopi', '', '10', '', ''],              // error: no unit
        ['Susu', 'ml', '', '', ''],              // error: twice
    ]);

    $preview = previewOf($this, 'ingredients', $file, $manager)->assertOk()->json();
    expect($preview['summary'])->toMatchArray(['total' => 4, 'new' => 1, 'update' => 1, 'error' => 2]);

    $this->actingAs($manager)->postJson('/import/ingredients/apply', ['rows' => $preview['payload']])->assertOk()->assertJsonPath('message', 'Impor selesai: 1 bahan baru, 1 diperbarui.');
    expect(Ingredient::query()->where('name', 'Susu')->first())->toMatchArray(['unit' => 'ml', 'stock' => 8000.0, 'avg_cost' => 20.0]);
    expect(Ingredient::query()->where('name', 'Gula')->first())->toMatchArray(['unit' => 'kg', 'min_stock' => 200.0, 'stock' => 50.0]);
    expect(Ingredient::query()->where('name', 'Kopi')->exists())->toBeFalse();
});

// ---------------------------------------------------------------- purchases

it('imports a purchase: stock and average cost rise, new ingredients are created, blank rows are skipped', function () {
    $beans = ing('Biji kopi', 'g', 1000, 50);
    $manager = userWithRole('manager');
    $file = sheetFile([
        ['Bahan *', 'Satuan', 'Jumlah *', 'Harga per satuan *'],
        ['Biji kopi', 'g', '1.000', '70'],
        ['Susu', 'ml', '5000', '20'],   // new
        ['Gula', '', '', ''],            // template row left empty
        ['Teh', '', '10', '5'],          // error: unknown, no unit
    ]);

    $preview = previewOf($this, 'purchase', $file, $manager)->assertOk()->json();
    expect($preview['summary'])->toMatchArray(['total' => 3, 'ok' => 1, 'new' => 1, 'error' => 1]);

    $this->actingAs($manager)->postJson('/import/purchase/apply', ['rows' => $preview['payload'], 'extra' => ['supplier' => 'Toko Maju', 'purchased_on' => '2026-10-20', 'hack' => 'x']])->assertOk();
    expect($beans->fresh()->stock)->toBe(2000.0)->and($beans->fresh()->avg_cost)->toBe(60.0);
    expect(Ingredient::query()->where('name', 'Susu')->first()->stock)->toBe(5000.0);
    $purchase = Purchase::query()->first();
    expect($purchase->supplier)->toBe('Toko Maju')->and($purchase->purchased_on->toDateString())->toBe('2026-10-20')->and($purchase->total)->toBe(70000 + 100000);
});

// ---------------------------------------------------------------- stock count

it('fills the staff count draft from a sheet, and only managers may add new ingredients that way', function () {
    $sugar = ing('Gula', 'g', 100);
    $file = fn () => sheetFile([['Bahan *', 'Satuan', 'Hitungan *'], ['Gula', 'g', '90'], ['Kopi', 'g', '5'], ['Susu', 'ml', '']]);

    $cashier = userWithRole('cashier');
    $preview = previewOf($this, 'count', $file(), $cashier)->assertOk()->json();
    expect($preview['summary'])->toMatchArray(['total' => 2, 'ok' => 1, 'error' => 1]);
    $this->actingAs($cashier)->postJson('/import/count/apply', ['rows' => $preview['payload']])->assertOk();
    $draft = StockCount::query()->where('user_id', $cashier->id)->where('status', 'draft')->first();
    expect($draft->lines()->pluck('counted', 'ingredient_id')->all())->toBe([$sugar->id => 90.0]);
    expect($sugar->fresh()->stock)->toBe(100.0); // nothing applied until the count is sent and approved

    $manager = userWithRole('manager');
    $preview = previewOf($this, 'count', $file(), $manager)->assertOk()->json();
    expect($preview['summary'])->toMatchArray(['ok' => 1, 'new' => 1, 'error' => 0]);
    $this->actingAs($manager)->postJson('/import/count/apply', ['rows' => $preview['payload']])->assertOk();
    expect(Ingredient::query()->where('name', 'Kopi')->exists())->toBeTrue();
});

// ---------------------------------------------------------------- recipes

it('replaces the recipe of every menu item listed in the file', function () {
    $beans = ing('Biji kopi');
    $milk = ing('Susu', 'ml');
    $americano = product('Americano');
    RecipeLine::create(['product_id' => $americano->id, 'ingredient_id' => $milk->id, 'qty' => 1]);
    $manager = userWithRole('manager');

    $file = sheetFile([
        ['Menu *', 'Varian', 'Bahan *', 'Satuan', 'Jumlah *'],
        ['Americano', '', 'Biji kopi', 'g', '18'],
        ['Americano', '', 'Air', 'ml', '200'],        // new ingredient
        ['Croissant', '', '', '', ''],                  // blank template row
        ['Es teh', '', 'Teh', 'g', '5'],                // error: unknown menu
        ['Americano', 'XXL', 'Biji kopi', '', '1'],     // error: unknown variant
    ]);
    $preview = previewOf($this, 'recipes', $file, $manager)->assertOk()->json();
    expect($preview['summary'])->toMatchArray(['total' => 4, 'ok' => 1, 'new' => 1, 'error' => 2]);

    $this->actingAs($manager)->postJson('/import/recipes/apply', ['rows' => $preview['payload']])->assertOk();
    $lines = RecipeLine::query()->where('product_id', $americano->id)->get();
    expect($lines)->toHaveCount(2)->and($lines->pluck('ingredient_id')->contains($milk->id))->toBeFalse()->and(Ingredient::query()->where('name', 'Air')->exists())->toBeTrue();
    expect(app(InventoryService::class)->portionCost($americano->id))->toBe(0.0); // costs are still 0 until purchases
});

// ---------------------------------------------------------------- products

it('updates prices in bulk, adds new menu items and categories, and protects SKUs', function () {
    $americano = product('Americano');
    $nasi = product('Nasi Goreng');
    $nasi->update(['sku' => 'MK-002']);
    $manager = userWithRole('manager');

    $file = sheetFile([
        ['Menu *', 'Kategori *', 'Harga *', 'SKU', 'Stok porsi', 'Deskripsi', 'Tersedia'],
        ['Americano', 'Kopi', '25.000', '', '', '', ''],            // price update by name
        ['Mie Goreng', 'Makanan Baru', '30000', 'MK-050', '10', 'Pedas', 'ya'],  // new item + new category
        ['Kentang', 'Makanan', '15000', 'MK-002', '', '', ''],     // SKU belongs to Nasi Goreng, different name => updates that item
        ['Roti', 'Makanan', 'abc', '', '', '', ''],                // error
    ]);
    $preview = previewOf($this, 'products', $file, $manager)->assertOk()->json();
    expect($preview['summary'])->toMatchArray(['total' => 4, 'new' => 1, 'update' => 2, 'error' => 1]);

    $this->actingAs($manager)->postJson('/import/products/apply', ['rows' => $preview['payload']])->assertOk();
    expect($americano->fresh()->base_price)->toBe(25000);
    $mie = Product::query()->where('name', 'Mie Goreng')->first();
    expect($mie->base_price)->toBe(30000)->and($mie->stock_qty)->toBe(10)->and(Category::query()->whereKey($mie->category_id)->value('name'))->toBe('Makanan Baru');
    expect($nasi->fresh()->name)->toBe('Kentang')->and($nasi->fresh()->base_price)->toBe(15000);
});

it('rejects a SKU used by another menu item', function () {
    product('Americano')->update(['sku' => 'KP-002']);
    $file = sheetFile([['Menu *', 'Kategori *', 'Harga *', 'SKU'], ['Menu Baru', 'Kopi', '1000', 'KP-002']]);
    $preview = previewOf($this, 'products', $file, userWithRole('manager'))->assertOk()->json();
    expect($preview['summary']['update'])->toBe(1); // same SKU = same item (renamed)
});

// ---------------------------------------------------------------- assets

it('imports equipment: new ones get a code, existing codes are updated, labels and dates are understood', function () {
    $existing = Asset::create(['outlet_id' => (int) Outlet::query()->value('id'), 'code' => 'AST-0001', 'name' => 'Blender', 'quantity' => 1, 'condition' => 'good']);
    $manager = userWithRole('manager');
    $file = sheetFile([
        ['Kode', 'Nama *', 'Kategori', 'Merek', 'Model', 'No. seri', 'Jumlah', 'Tgl beli', 'Harga per unit', 'Lokasi', 'Kondisi', 'Catatan'],
        ['AST-0001', 'Blender besar', '', '', '', '', '2', '15/03/2026', '750.000', 'Bar', 'Perlu servis', ''],
        ['', 'Mesin espresso', 'Mesin kopi', 'La Marzocco', 'Linea', 'SN1', '1', '2026-01-10', '60000000', 'Bar', 'Baik', ''],
        ['', 'Kursi', '', '', '', '', '10', 'bukan tanggal', '', '', '', ''],
        ['', 'Meja', '', '', '', '', '5', '', '', '', 'Hilang', ''],
    ]);
    $preview = previewOf($this, 'assets', $file, $manager)->assertOk()->json();
    expect($preview['summary'])->toMatchArray(['total' => 4, 'new' => 1, 'update' => 1, 'error' => 2]);

    $this->actingAs($manager)->postJson('/import/assets/apply', ['rows' => $preview['payload']])->assertOk();
    expect($existing->fresh())->toMatchArray(['name' => 'Blender besar', 'quantity' => 2, 'condition' => 'needs_service', 'purchase_price' => 750000]);
    expect($existing->fresh()->purchased_on->toDateString())->toBe('2026-03-15');
    $new = Asset::query()->where('name', 'Mesin espresso')->first();
    expect($new->code)->toBe('AST-0002')->and($new->brand)->toBe('La Marzocco');
});

// ---------------------------------------------------------------- guards

it('checks the permission of each import type', function () {
    $cashier = userWithRole('cashier');
    foreach (['ingredients', 'purchase', 'recipes', 'products', 'assets'] as $type) {
        $this->actingAs($cashier)->get("/import/{$type}/template")->assertForbidden();
        $this->actingAs($cashier)->postJson("/import/{$type}/apply", ['rows' => [['line' => 2, 'cells' => ['name' => 'x']]]])->assertForbidden();
    }
    $this->actingAs($cashier)->get('/import/count/template')->assertOk();
    $this->actingAs($cashier)->getJson('/import/bogus/template')->assertStatus(422);
});

it('does not trust the preview: apply checks again and refuses rows with errors', function () {
    ing('Gula', 'g', 10);
    $manager = userWithRole('manager');
    $forged = [['line' => 2, 'cells' => ['name' => 'Gula', 'qty' => '-5', 'unit_cost' => '10']]];
    $this->actingAs($manager)->postJson('/import/purchase/apply', ['rows' => $forged])->assertStatus(422)->assertJsonPath('message', 'Tidak ada baris yang bisa diimpor.');
    expect(Ingredient::query()->where('name', 'Gula')->value('stock'))->toBe(10.0);
});

it('finds the header row below a title block and refuses files without the needed columns', function () {
    $manager = userWithRole('manager');
    $file = sheetFile([['Daftar belanja minggu ini'], [''], ['Bahan', 'Satuan', 'Jumlah', 'Harga satuan'], ['Telur', 'pcs', '30', '2.500']]);
    $preview = previewOf($this, 'purchase', $file, $manager)->assertOk()->json();
    expect($preview['summary'])->toMatchArray(['total' => 1, 'new' => 1]);

    $bad = sheetFile([['Foo', 'Bar'], ['1', '2']]);
    previewOf($this, 'purchase', $bad, $manager)->assertStatus(422);
    $missing = sheetFile([['Bahan', 'Satuan'], ['Telur', 'pcs']]);
    previewOf($this, 'purchase', $missing, $manager)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Kolom wajib'));
});

it('needs the import permission on top of the screen permission', function () {
    $role = Role::findOrCreate('stok-saja', 'web')->syncPermissions(['manage-inventory', 'count-stock']);
    $user = User::factory()->create(['is_active' => true, 'outlet_id' => userWithRole('owner')->outlet_id]);
    $user->assignRole($role);

    $this->actingAs($user)->get('/import/purchase/template')->assertForbidden();
    $this->actingAs($user)->get('/import/count/template')->assertOk(); // staff filling their own count
    $role->givePermissionTo('import-data');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($user->fresh())->get('/import/purchase/template')->assertOk();
    $this->actingAs($user->fresh())->get('/import/products/template')->assertForbidden(); // lacks manage-menu
});
