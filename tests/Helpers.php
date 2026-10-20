<?php

use App\Domain\Catalog\Models\Product;
use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\Actions\PlaceOrderAction;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Actions\RecordPaymentAction;
use App\Domain\Payment\Models\Bill;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Pricing\Models\TaxRule;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Shift\Actions\OpenShiftAction;
use App\Domain\Shift\Models\Shift;
use App\Models\User;
use Illuminate\Support\Str;

function userWithRole(string $role): User
{
    return User::query()->where('email', match ($role) {
        'owner' => 'owner@pos.test', 'manager' => 'manager@pos.test', 'cashier' => 'kasir@pos.test',
        'waiter' => 'waiter@pos.test', 'kitchen' => 'dapur@pos.test',
    })->firstOrFail();
}

function openShiftFor(User $user, int $opening = 100000): Shift
{
    return app(OpenShiftAction::class)->handle($user, $opening, 'tablet-1');
}

function product(string $name): Product
{
    return Product::query()->where('name', $name)->firstOrFail();
}

/** @param  list<array<string, mixed>>|null  $items */
function placeOrder(?User $user = null, ?array $items = null, string $type = 'dine_in', ?int $tableId = null, array $extra = []): Order
{
    $user ??= userWithRole('cashier');
    $items ??= [['product_id' => product('Americano')->id, 'qty' => 2]]; // 2 x Rp22.000 = 44.000
    if ($type === 'dine_in' && $tableId === null) {
        $tableId = Table::query()->orderBy('id')->value('id');
    }

    return app(PlaceOrderAction::class)->handle($user, ['type' => $type, 'table_id' => $tableId, 'items' => $items] + $extra);
}

function method(string $type): PaymentMethod
{
    return PaymentMethod::query()->where('type', $type)->firstOrFail();
}

function pay(Bill|Order $target, ?User $user = null, string $type = 'cash', ?int $amount = null, ?string $key = null)
{
    $user ??= userWithRole('cashier');
    $bill = $target instanceof Order ? $target->bills()->firstOrFail() : $target;
    $amount ??= $bill->remaining();

    return app(RecordPaymentAction::class)->handle($bill, $user, method($type)->id, $amount, $key ?? (string) Str::uuid());
}

function enableTax(bool $inclusive = false): void
{
    TaxRule::query()->where('name', 'Service Charge')->update(['is_active' => true]);
    TaxRule::query()->where('name', 'PB1')->update(['is_active' => true, 'is_inclusive' => $inclusive]);
}

function setting(string $key, mixed $value): void
{
    app(SettingsRepository::class)->set($key, $value);
}

/** Every journal entry must balance. */
function assertJournalsBalanced(): void
{
    $bad = DB::table('journal_lines')->selectRaw('journal_entry_id, SUM(debit) d, SUM(credit) c')
        ->groupBy('journal_entry_id')->havingRaw('SUM(debit) <> SUM(credit)')->count();
    expect($bad)->toBe(0);
}

function accountBalance(string $systemKey): int
{
    $id = DB::table('accounts')->where('system_key', $systemKey)->value('id');
    $row = DB::table('journal_lines')->where('account_id', $id)->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();

    return (int) $row->d - (int) $row->c; // debit-positive
}
