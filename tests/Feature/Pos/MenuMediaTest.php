<?php

use App\Domain\Catalog\Models\Product;
use App\Domain\Floor\Models\Table;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function productForm(array $extra = []): array
{
    $p = product('Americano');

    return $extra + [
        'name' => $p->name, 'category_id' => $p->category_id, 'station_id' => $p->station_id, 'base_price' => $p->base_price,
        'description' => 'Espresso dengan air panas, rasa bold.', 'is_available' => 1,
    ];
}

it('stores a product photo as WebP, max 800 px and 120 KB, and serves it by a relative URL', function () {
    Storage::fake('public');
    $manager = userWithRole('manager');
    $p = product('Americano');

    $this->actingAs($manager)->post("/admin/products/{$p->id}", productForm(['image' => UploadedFile::fake()->image('kopi.jpg', 3000, 2000)]))->assertSessionHasNoErrors();

    $path = $p->fresh()->image_path;
    expect($path)->toEndWith('.webp');
    Storage::disk('public')->assertExists($path);
    expect(Storage::disk('public')->size($path))->toBeLessThanOrEqual(120 * 1024);
    [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($path));
    expect(max($w, $h))->toBeLessThanOrEqual(800);

    $this->actingAs(userWithRole('cashier'))->get('/pos')->assertInertia(fn ($page) => $page->where('menu', fn ($menu) => collect($menu)->flatMap(fn ($c) => $c['products'])->firstWhere('name', 'Americano')['image'] === "/storage/{$path}"));
});

it('rejects non-image uploads and keeps the old photo when no new file is sent', function () {
    Storage::fake('public');
    $manager = userWithRole('manager');
    $p = product('Americano');

    $this->actingAs($manager)->post("/admin/products/{$p->id}", productForm(['image' => UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf')]))->assertSessionHasErrors('image');

    $this->actingAs($manager)->post("/admin/products/{$p->id}", productForm(['image' => UploadedFile::fake()->image('a.png', 400, 400)]));
    $path = $p->fresh()->image_path;
    $this->actingAs($manager)->post("/admin/products/{$p->id}", productForm(['name' => 'Americano Baru']));
    expect($p->fresh()->image_path)->toBe($path)->and($p->fresh()->name)->toBe('Americano Baru');
});

it('shows description and photo on the public QR menu', function () {
    $p = product('Americano');
    $p->update(['description' => 'Espresso dengan air panas', 'image_path' => 'products/x.webp']);
    $token = Table::query()->first()->qr_token;

    $this->get("/m/{$token}")->assertInertia(fn ($page) => $page->where('menu', function ($menu) {
        $item = collect($menu)->flatMap(fn ($c) => $c['products'])->firstWhere('name', 'Americano');

        return $item['description'] === 'Espresso dengan air panas' && $item['image'] === '/storage/products/x.webp';
    }));
    expect(Product::query()->where('name', 'Americano')->count())->toBe(1);
});

it('marks a product as favorite and exposes it on the QR and POS menus', function () {
    $owner = userWithRole('owner');
    $p = product('Americano');
    $token = Table::query()->first()->qr_token;

    $this->actingAs($owner)->post("/admin/products/{$p->id}/favorite", ['is_favorite' => true])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');
    expect($p->fresh()->is_favorite)->toBeTrue();

    $this->get("/m/{$token}")->assertInertia(fn ($page) => $page->where('menu', function ($menu) {
        $item = collect($menu)->flatMap(fn ($c) => $c['products'])->firstWhere('name', 'Americano');

        return $item && $item['is_favorite'] === true;
    }));

    $this->actingAs(userWithRole('cashier'))->get('/pos')->assertInertia(fn ($page) => $page->where('menu', function ($menu) {
        $item = collect($menu)->flatMap(fn ($c) => $c['products'])->firstWhere('name', 'Americano');

        return $item && $item['is_favorite'] === true;
    }));

    $this->actingAs($owner)->post("/admin/products/{$p->id}/favorite", ['is_favorite' => false])->assertSessionHasNoErrors();
    expect($p->fresh()->is_favorite)->toBeFalse();
});

it('saves a default modifier option and exposes it on the menu', function () {
    $owner = userWithRole('owner');
    $group = \App\Domain\Catalog\Models\ModifierGroup::query()->where('name', 'Sugar Level')->firstOrFail();

    $this->actingAs($owner)->put("/admin/modifier-groups/{$group->id}", [
        'name' => 'Sugar Level',
        'min_select' => 1,
        'max_select' => 1,
        'is_required' => true,
        'modifiers' => [
            ['name' => 'Normal', 'price_delta' => 0, 'is_default' => true],
            ['name' => 'Less Sugar', 'price_delta' => 0, 'is_default' => false],
            ['name' => 'No Sugar', 'price_delta' => 0, 'is_default' => false],
        ],
    ])->assertSessionHasNoErrors();

    expect($group->fresh()->modifiers()->where('name', 'Normal')->value('is_default'))->toBeTrue()
        ->and($group->fresh()->modifiers()->where('name', 'Less Sugar')->value('is_default'))->toBeFalse();

    $this->actingAs(userWithRole('cashier'))->get('/pos')->assertInertia(fn ($page) => $page->where('menu', function ($menu) {
        $latte = collect($menu)->flatMap(fn ($c) => $c['products'])->firstWhere('name', 'Cafe Latte')
            ?? collect($menu)->flatMap(fn ($c) => $c['products'])->first(fn ($p) => collect($p['modifier_groups'] ?? [])->contains('name', 'Sugar Level'));
        if (! $latte) {
            return false;
        }
        $sugar = collect($latte['modifier_groups'])->firstWhere('name', 'Sugar Level');
        $normal = collect($sugar['modifiers'] ?? [])->firstWhere('name', 'Normal');

        return $normal && $normal['is_default'] === true;
    }));
});
