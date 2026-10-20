<?php

use App\Domain\Catalog\Models\Product;
use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\Actions\PlaceOrderAction;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shift\Actions\OpenShiftAction;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/*
| Two real OS processes, two real PostgreSQL connections, committed data.
| (RefreshDatabase wraps a test in one transaction, which would hide races.)
*/
uses(TestCase::class);

beforeEach(function () {
    Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
});

/** @return array{bill: int, user: int, method: int, amount: int} */
function raceFixture(): array
{
    $cashier = User::query()->where('email', 'kasir@pos.test')->firstOrFail();
    app(OpenShiftAction::class)->handle($cashier, 0);
    $order = app(PlaceOrderAction::class)->handle($cashier, [
        'type' => 'dine_in', 'table_id' => Table::query()->value('id'),
        'items' => [['product_id' => Product::query()->where('name', 'Americano')->value('id'), 'qty' => 2]],
    ]);

    return [
        'bill' => (int) $order->bills()->value('id'), 'user' => $cashier->id,
        'method' => (int) PaymentMethod::query()->where('type', 'cash')->value('id'), 'amount' => 44000,
    ];
}

/**
 * @param  list<string>  $keys  one idempotency key per racing process
 * @return list<array<string, mixed>>
 */
function race(array $f, array $keys): array
{
    $startAt = (int) (microtime(true) * 1000) + 2500; // boot time budget before the barrier opens
    $procs = array_map(function (string $key) use ($f, $startAt) {
        $p = new Process([PHP_BINARY, base_path('artisan'), 'pos:concurrency-probe', $f['bill'], $f['user'], $f['method'], $f['amount'], $key, $startAt], base_path(), [
            'APP_ENV' => 'testing', 'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null', 'SESSION_DRIVER' => 'array',
        ]);
        $p->start();

        return $p;
    }, $keys);

    return array_map(function (Process $p) {
        $p->wait();
        $line = collect(explode("\n", trim($p->getOutput())))->last(fn ($l) => str_starts_with($l, '{'));
        expect($line)->not->toBeNull($p->getErrorOutput().$p->getOutput());

        return json_decode((string) $line, true);
    }, $procs);
}

it('lets exactly one of two cashiers pay the same bill', function () {
    $results = race(raceFixture(), ['key-a', 'key-b']);

    expect(collect($results)->where('ok', true))->toHaveCount(1)
        ->and(collect($results)->where('ok', false)->first()['error'])->toContain('lunas');
    expect(DB::table('payments')->count())->toBe(1)
        ->and(DB::table('journal_entries')->count())->toBe(1)
        ->and(DB::table('orders')->value('status'))->toBe('paid');
});

it('returns the same payment for two simultaneous requests with one idempotency key', function () {
    $results = race(raceFixture(), ['same-key', 'same-key']);

    expect(collect($results)->pluck('ok')->all())->toBe([true, true])
        ->and(collect($results)->pluck('payment_id')->unique())->toHaveCount(1);
    expect(DB::table('payments')->count())->toBe(1)
        ->and(DB::table('journal_entries')->count())->toBe(1);
});
