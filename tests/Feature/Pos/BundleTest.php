<?php

use App\Domain\Catalog\Models\ModifierGroup;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Station;
use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\StockService;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Domain\Printing\PrintDispatcher;
use App\Domain\Reporting\SalesReports;
use App\Domain\Settings\Models\Outlet;
use App\Http\Presenters\MenuPresenter;
use App\Models\User;
use Spatie\Permission\Models\Role;

function bundlePrinter(): Printer
{
    $outlet = (int) Outlet::query()->value('id');
    $p = Printer::create(['outlet_id' => $outlet, 'name' => 'Ticket '.Printer::query()->count(), 'kind' => 'ticket', 'paper_width' => 58, 'copies' => 1]);
    PrintDispatcher::forgetRouting($outlet);

    return $p;
}

/** A bundle of 1 Americano and 2 Croissant for Rp50.000. */
function makeBundle(): Product
{
    $americano = product('Americano');
    $bundle = Product::create([
        'outlet_id' => $americano->outlet_id, 'category_id' => $americano->category_id, 'station_id' => null,
        'name' => 'Paket Hemat', 'base_price' => 50000, 'is_bundle' => true,
    ]);
    $bundle->bundleItems()->create(['product_id' => $americano->id, 'qty' => 1, 'sort' => 0]);
    $bundle->bundleItems()->create(['product_id' => product('Croissant')->id, 'qty' => 2, 'sort' => 1]);

    return $bundle;
}

it('prices a bundle as one line and prints each menu item on its own printer', function () {
    $bar = bundlePrinter();
    $kitchen = bundlePrinter();
    product('Americano')->printers()->sync([$bar->id]);
    product('Croissant')->printers()->sync([$kitchen->id]);
    $bundle = makeBundle();

    $order = placeOrder(items: [['product_id' => $bundle->id, 'qty' => 2]]);

    expect($order->items)->toHaveCount(1)->and($order->subtotal)->toBe(100000);
    $jobs = PrintJob::query()->where('kind', 'ticket')->get()->keyBy('printer_id');
    expect($jobs)->toHaveCount(2);
    $barLine = $jobs[$bar->id]->payload['items'][0];
    $kitchenLine = $jobs[$kitchen->id]->payload['items'][0];
    expect($jobs[$bar->id]->payload['items'])->toHaveCount(1)
        ->and($barLine['name'])->toBe('Americano')->and($barLine['qty'])->toBe(2)->and($barLine['bundle'])->toBe('Paket Hemat')
        ->and($kitchenLine['name'])->toBe('Croissant')->and($kitchenLine['qty'])->toBe(4);
});

it('counts the portions of the menu items inside a bundle and gives them back on release', function () {
    $bundle = makeBundle();
    $croissant = product('Croissant');
    $before = $croissant->stock_qty;

    $order = placeOrder(items: [['product_id' => $bundle->id, 'qty' => 2]]);
    expect($croissant->fresh()->stock_qty)->toBe($before - 4);

    app(StockService::class)->release($order->items->first()->fresh());
    expect($croissant->fresh()->stock_qty)->toBe($before);
});

it('refuses a bundle when a menu item inside it is short, sold out or not sold', function () {
    $bundle = makeBundle();
    $croissant = product('Croissant');

    $croissant->update(['stock_qty' => 3]);
    expect(fn () => placeOrder(items: [['product_id' => $bundle->id, 'qty' => 2]]))->toThrow(OrderException::class, 'Stok Croissant tinggal 3');

    $croissant->update(['stock_qty' => null, 'is_available' => false]);
    expect(fn () => placeOrder(items: [['product_id' => $bundle->id, 'qty' => 1]]))->toThrow(OrderException::class, 'sedang habis');

    $croissant->update(['is_available' => true, 'is_active' => false]);
    expect(fn () => placeOrder(items: [['product_id' => $bundle->id, 'qty' => 1]]))->toThrow(OrderException::class, 'tidak dijual');
});

it('lets a role with the bundle permission build bundles and rejects bad contents', function () {
    $owner = userWithRole('owner');
    $americano = product('Americano');
    $body = fn (array $components, array $extra = []) => [
        'name' => 'Paket A', 'category_id' => $americano->category_id, 'base_price' => 45000, 'is_bundle' => true, 'components' => $components,
    ] + $extra;

    $this->actingAs($owner)->post('/admin/products', $body([['product_id' => $americano->id, 'qty' => 2], ['product_id' => product('Croissant')->id, 'qty' => 1]]))->assertSessionHasNoErrors();
    $saved = Product::query()->where('name', 'Paket A')->firstOrFail();
    expect($saved->is_bundle)->toBeTrue()->and($saved->bundleItems)->toHaveCount(2);
    $this->actingAs($owner)->get('/admin/menu')->assertInertia(fn ($p) => $p->where('canBundles', true));

    // empty, duplicated, nested and variant contents are all refused
    $this->actingAs($owner)->post("/admin/products/{$saved->id}", $body([]))->assertSessionHasErrors('components');
    $this->actingAs($owner)->post("/admin/products/{$saved->id}", $body([['product_id' => $americano->id, 'qty' => 1], ['product_id' => $americano->id, 'qty' => 1]]))->assertSessionHasErrors('components');
    $this->actingAs($owner)->post('/admin/products', $body([['product_id' => $saved->id, 'qty' => 1]], ['name' => 'Paket B']))->assertSessionHasErrors('components');
});

it('keeps the chosen variant of a menu item in the bundle and prints it on the ticket', function () {
    $owner = userWithRole('owner');
    $latte = product('Caffe Latte');
    $hot = $latte->variants()->create(['name' => 'Hot', 'price' => 28000, 'sort' => 0]);
    $iced = $latte->variants()->create(['name' => 'Iced', 'price' => 30000, 'sort' => 1]);
    $body = fn (array $components) => ['name' => 'Paket Kopi', 'category_id' => $latte->category_id, 'base_price' => 40000, 'is_bundle' => true, 'components' => $components];

    $other = product('Americano');
    $foreign = $other->variants()->create(['name' => 'Large', 'price' => 25000, 'sort' => 0]);
    $this->actingAs($owner)->post('/admin/products', $body([['product_id' => $latte->id, 'variant_id' => $foreign->id, 'qty' => 1]]))->assertSessionHasErrors('components');

    $this->actingAs($owner)->post('/admin/products', $body([['product_id' => $latte->id, 'variant_id' => $iced->id, 'qty' => 2]]))->assertSessionHasNoErrors();
    $bundle = Product::query()->where('name', 'Paket Kopi')->firstOrFail();
    expect($bundle->bundleItems->first()->variant_id)->toBe($iced->id);

    $printer = bundlePrinter();
    $order = placeOrder(items: [['product_id' => $bundle->id, 'qty' => 1]]);
    $line = PrintJob::query()->where('printer_id', $printer->id)->firstOrFail()->payload['items'][0];
    expect($order->subtotal)->toBe(40000)->and($line['name'])->toBe('Caffe Latte (Iced)')->and($line['qty'])->toBe(2);

    // the variant going sold out stops the bundle
    $iced->update(['is_available' => false]);
    expect(fn () => placeOrder(items: [['product_id' => $bundle->id, 'qty' => 1]], tableId: Table::query()->skip(1)->value('id')))->toThrow(OrderException::class, 'sedang habis');
    unset($hot);
});

it('leaves bundles alone for someone who manages the menu but not bundles', function () {
    $role = Role::findOrCreate('menu-only', 'web');
    $role->givePermissionTo('manage-menu');
    $user = User::factory()->create();
    $user->assignRole($role);
    $americano = product('Americano');

    $this->actingAs($user)->get('/admin/menu')->assertInertia(fn ($p) => $p->where('canBundles', false));
    $this->actingAs($user)->post('/admin/products', [
        'name' => 'Paket X', 'category_id' => $americano->category_id, 'base_price' => 10000, 'is_bundle' => true,
        'components' => [['product_id' => $americano->id, 'qty' => 1]],
    ])->assertSessionHasNoErrors();

    $made = Product::query()->where('name', 'Paket X')->firstOrFail();
    expect($made->is_bundle)->toBeFalse()->and($made->bundleItems)->toHaveCount(0);
});

it('lets the guest choose variant and options inside a bundle, adds paid options to the price and sends each choice to the right ticket', function () {
    $owner = userWithRole('owner');
    $bar = bundlePrinter();
    $kitchen = bundlePrinter();
    $tea = product('Americano');
    $chicken = product('Nasi Goreng');
    $tea->printers()->sync([$bar->id]);
    $chicken->printers()->sync([$kitchen->id]);
    $hot = $tea->variants()->create(['name' => 'Hot', 'price' => 22000, 'sort' => 0]);
    $ice = $tea->variants()->create(['name' => 'Ice', 'price' => 22000, 'sort' => 1]);
    $sweet = ModifierGroup::create(['outlet_id' => $tea->outlet_id, 'name' => 'Manis', 'min_select' => 1, 'max_select' => 1, 'is_required' => true]);
    $sweetYes = $sweet->modifiers()->create(['name' => 'Manis', 'price_delta' => 0]);
    $sweet->modifiers()->create(['name' => 'Tawar', 'price_delta' => 0]);
    $tea->modifierGroups()->sync([$sweet->id]);
    $sambal = ModifierGroup::create(['outlet_id' => $tea->outlet_id, 'name' => 'Sambal', 'min_select' => 0, 'max_select' => 1, 'is_required' => false]);
    $matah = $sambal->modifiers()->create(['name' => 'Matah', 'price_delta' => 2000]);
    $chicken->modifierGroups()->sync([$sambal->id]);

    $this->actingAs($owner)->post('/admin/products', [
        'name' => 'Paket 1', 'category_id' => $tea->category_id, 'base_price' => 30000, 'is_bundle' => true,
        'components' => [
            ['product_id' => $chicken->id, 'qty' => 1, 'ask_modifiers' => true],
            ['product_id' => $tea->id, 'qty' => 1, 'ask_modifiers' => true],
        ],
    ])->assertSessionHasNoErrors();
    $bundle = Product::query()->where('name', 'Paket 1')->firstOrFail();
    $tableId = fn (int $n) => Table::query()->skip($n)->value('id');

    // the menu hands the guest exactly the choices that are open
    $menu = collect(MenuPresenter::categories($bundle->outlet_id))->flatMap(fn ($c) => $c['products'])->firstWhere('id', $bundle->id);
    expect(collect($menu['bundle'])->pluck('name')->all())->toBe(['Nasi Goreng', 'Americano'])
        ->and($menu['bundle'][1]['variants'])->toHaveCount(2)->and($menu['bundle'][1]['modifier_groups'])->toHaveCount(1);

    // missing variant or a required option is refused
    expect(fn () => placeOrder(items: [['product_id' => $bundle->id, 'qty' => 1, 'parts' => [['product_id' => $tea->id, 'modifier_ids' => [$sweetYes->id]]]]]))->toThrow(OrderException::class, 'Pilih varian untuk Americano');
    expect(fn () => placeOrder(items: [['product_id' => $bundle->id, 'qty' => 1, 'parts' => [['product_id' => $tea->id, 'variant_id' => $ice->id]]]], tableId: $tableId(1)))->toThrow(OrderException::class, 'Pilih minimal 1 untuk Manis');
    // an option that belongs to another menu item is refused
    expect(fn () => placeOrder(items: [['product_id' => $bundle->id, 'qty' => 1, 'parts' => [['product_id' => $tea->id, 'variant_id' => $ice->id, 'modifier_ids' => [$sweetYes->id, $matah->id]]]]], tableId: $tableId(2)))->toThrow(OrderException::class, 'tidak valid');

    $order = placeOrder(items: [['product_id' => $bundle->id, 'qty' => 2, 'parts' => [
        ['product_id' => $chicken->id, 'modifier_ids' => [$matah->id]],
        ['product_id' => $tea->id, 'variant_id' => $ice->id, 'modifier_ids' => [$sweetYes->id]],
    ]]], tableId: $tableId(3));

    // Rp30.000 + the Rp2.000 sambal, twice; the choices are on the line the receipt shows
    expect($order->subtotal)->toBe(64000);
    $item = $order->items->first();
    expect(collect($item->modifiers_snapshot)->pluck('name')->all())->toBe(['Matah', 'Ice, Manis']);

    $jobs = PrintJob::query()->where('kind', 'ticket')->get()->keyBy('printer_id');
    expect($jobs[$kitchen->id]->payload['items'][0])->toMatchArray(['name' => 'Nasi Goreng', 'qty' => 2, 'modifiers' => ['Matah']])
        ->and($jobs[$bar->id]->payload['items'][0])->toMatchArray(['name' => 'Americano (Ice)', 'qty' => 2, 'modifiers' => ['Manis']]);
});

it('puts who ordered, from where, the order type, the batch and the item notes on the ticket', function () {
    $printer = bundlePrinter();
    openShiftFor($cashier = userWithRole('cashier'), 0);
    $order = placeOrder($cashier, [['product_id' => product('Americano')->id, 'qty' => 1, 'note' => 'less ice']], 'takeaway', null, ['customer_name' => 'Rani']);

    $payload = PrintJob::query()->where('printer_id', $printer->id)->firstOrFail()->payload;

    expect($payload['customer'])->toBe('Rani')->and($payload['type'])->toBe('TAKE AWAY')->and($payload['source'])->toBe('POS')
        ->and($payload['by'])->toBe($cashier->name)->and($payload['batch'])->toBe(1)
        ->and($payload['items'][0]['note'])->toBe('less ice');
});

it('takes the kitchen or bar screen from the category unless the menu item has its own', function () {
    $bar = Station::query()->first();
    $other = Station::query()->where('id', '!=', $bar->id)->first() ?? Station::create(['outlet_id' => $bar->outlet_id, 'name' => 'Extra', 'is_active' => true]);
    $americano = product('Americano');
    $americano->category->update(['station_id' => $bar->id]);
    $americano->update(['station_id' => null]);

    openShiftFor($cashier = userWithRole('cashier'), 0);
    $order = placeOrder($cashier, [['product_id' => $americano->id, 'qty' => 1]]);
    expect($order->items->first()->station_id)->toBe($bar->id);

    $americano->update(['station_id' => $other->id]);
    $again = placeOrder($cashier, [['product_id' => $americano->id, 'qty' => 1]]);
    expect($again->items->last()->station_id)->toBe($other->id);

    $manager = userWithRole('manager');
    $this->actingAs($manager)->put("/admin/categories/{$americano->category_id}", ['name' => $americano->category->name, 'station_id' => null])->assertRedirect();
    expect($americano->category->fresh()->station_id)->toBeNull();
});

it('reports how often each variant and each option was sold', function () {
    $latte = product('Caffe Latte');
    $variant = $latte->variants()->first();
    $group = $latte->modifierGroups()->with('modifiers')->first();
    $option = $group->modifiers->first();

    openShiftFor($cashier = userWithRole('cashier'), 0);
    $order = placeOrder($cashier, [['product_id' => $latte->id, 'variant_id' => $variant->id, 'qty' => 2, 'modifier_ids' => [$option->id]]], 'takeaway');
    pay($order, $cashier);
    $today = now()->toDateString();

    $variants = app(SalesReports::class)->by('variant', $today, $today);
    expect($variants->first()->orders)->toBe(2)->and($variants->first()->label)->toContain($variant->name);

    $options = app(SalesReports::class)->by('modifier', $today, $today);
    expect($options->first()->label)->toBe("{$group->name}: {$option->name}")->and($options->first()->orders)->toBe(2)->and($options->first()->total)->toBe(2 * $option->price_delta);
});
