<?php

use App\Domain\Reporting\Jobs\ExportReceiptsPdfJob;
use App\Domain\Reporting\Models\ReceiptExport;
use App\Domain\Reporting\ReceiptArchive;
use App\Domain\Settings\Models\Outlet;
use App\Domain\Shift\Models\Shift;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake(ReceiptArchive::DISK));

function paidOn(string $date): int
{
    $cashier = userWithRole('cashier');
    if (! Shift::query()->whereNull('closed_at')->exists()) {
        openShiftFor($cashier, 0);
    }
    $order = placeOrder($cashier, type: 'takeaway');
    pay($order, $cashier);
    $order->forceFill(['business_date' => $date])->save();

    return $order->id;
}

it('builds one PDF with the receipts of a past month in another year and leaves other months out', function () {
    paidOn('2024-03-05');
    paidOn('2024-03-31');
    paidOn('2024-04-01');
    paidOn('2023-03-10');
    $owner = userWithRole('owner');

    $this->actingAs($owner)->getJson('/admin/receipt-exports/preview?year=2024&month=3')->assertOk()->assertJson(['count' => 2, 'total' => 88000]);

    $this->actingAs($owner)->post('/admin/receipt-exports', ['year' => 2024, 'month' => 3])->assertRedirect();

    $export = ReceiptExport::query()->firstOrFail();
    expect($export->status)->toBe('done')->and($export->receipts)->toBe(2)->and($export->total)->toBe(88000)->and($export->size)->toBeGreaterThan(1000);
    $bytes = Storage::disk(ReceiptArchive::DISK)->get((string) $export->path);
    expect(substr((string) $bytes, 0, 5))->toBe('%PDF-');

    $this->actingAs($owner)->get("/admin/receipt-exports/{$export->id}/download")->assertOk()->assertDownload('struk-2024-03.pdf');
    expect(Storage::disk(ReceiptArchive::DISK)->allFiles('receipt-exports'))->toHaveCount(1); // temp parts cleaned
});

it('joins batches into one file when a month has more receipts than one batch', function () {
    foreach (range(1, 5) as $_) {
        paidOn('2024-05-10');
    }
    $export = ReceiptExport::query()->create(['outlet_id' => Outlet::query()->value('id'), 'year' => 2024, 'month' => 5]);
    $archive = new class extends ReceiptArchive
    {
        public const BATCH = 2;
    };
    app()->instance(ReceiptArchive::class, $archive);
    $archive->build($export);

    expect($export->fresh()->status)->toBe('done')->and($export->fresh()->receipts)->toBe(5);
});

it('refuses an empty month and a second request while one is running', function () {
    $owner = userWithRole('owner');
    $this->actingAs($owner)->post('/admin/receipt-exports', ['year' => 2022, 'month' => 1])->assertSessionHas('error');
    expect(ReceiptExport::query()->count())->toBe(0);

    paidOn('2024-06-02');
    ReceiptExport::query()->create(['outlet_id' => Outlet::query()->value('id'), 'year' => 2024, 'month' => 6, 'status' => 'running']);
    $this->actingAs($owner)->post('/admin/receipt-exports', ['year' => 2024, 'month' => 6])->assertSessionHas('error');
    expect(ReceiptExport::query()->count())->toBe(1);
});

it('is for owner and manager only', function () {
    foreach (['cashier', 'waiter', 'kitchen'] as $role) {
        $this->actingAs(userWithRole($role))->get('/admin/receipt-exports')->assertForbidden();
    }
    foreach (['owner', 'manager'] as $role) {
        $this->actingAs(userWithRole($role))->get('/admin/receipt-exports')->assertOk();
    }
});

it('prunes old files', function () {
    $export = ReceiptExport::query()->create(['outlet_id' => Outlet::query()->value('id'), 'year' => 2024, 'month' => 1, 'status' => 'done', 'path' => 'receipt-exports/x.pdf']);
    Storage::disk(ReceiptArchive::DISK)->put('receipt-exports/x.pdf', 'x');
    $export->forceFill(['created_at' => now()->subDays(40)])->save();
    $this->artisan('receipt-exports:prune')->assertSuccessful();
    expect(ReceiptExport::query()->count())->toBe(0);
    Storage::disk(ReceiptArchive::DISK)->assertMissing('receipt-exports/x.pdf');
});

it('queues the monthly PDF on its own worker that has the memory and time it needs', function () {
    Queue::fake();
    ExportReceiptsPdfJob::dispatch(1);
    Queue::assertPushedOn('exports', ExportReceiptsPdfJob::class);

    $worker = config('horizon.defaults.supervisor-exports');
    expect($worker['queue'])->toBe(['exports'])->and($worker['memory'])->toBeGreaterThanOrEqual(1024)->and($worker['timeout'])->toBeGreaterThanOrEqual(1800);
    expect((int) config('queue.connections.redis.retry_after'))->toBeGreaterThan($worker['timeout']);
});
