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
