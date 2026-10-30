<?php

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\SystemAccount;
use App\Domain\Expense\Models\Expense;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Notification\ReportDelivery;
use App\Domain\Shift\Actions\CloseShiftAction;
use App\Domain\Shift\Models\CashMovement;
use App\Domain\Shift\ShiftCash;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function galon(): ExpenseCategory
{
    return ExpenseCategory::query()->firstOrFail();
}

it('records cash spent from the drawer once: movement, expense row, one journal, drawer count drops', function () {
    $cashier = userWithRole('cashier');
    $shift = openShiftFor($cashier, 100000);
    $cat = galon();

    $this->actingAs($cashier)->post('/shift/movements', ['direction' => 'out', 'amount' => 25000, 'category_id' => $cat->id, 'reason' => 'galon 2 buah'])
        ->assertSessionHasNoErrors()->assertSessionHas('success');

    $move = CashMovement::query()->firstOrFail();
    expect($move->expense_category_id)->toBe($cat->id)->and($move->account_id)->toBe($cat->account_id)->and($move->reason)->toContain('galon 2 buah');
    $expense = Expense::query()->firstOrFail();
    expect($expense->cash_movement_id)->toBe($move->id)->and($expense->amount)->toBe(25000)->and($expense->source_account_id)->toBe(SystemAccount::CashDrawer->resolve()->id);
    expect(JournalEntry::query()->where('memo', 'like', 'Kas out:%')->count())->toBe(1)
        ->and(JournalEntry::query()->where('memo', 'like', 'Pengeluaran:%')->count())->toBe(0);
    expect(app(ShiftCash::class)->summarize($shift->fresh())['expected'])->toBe(75000);
});

it('keeps the receipt photo and shows it on the shift page', function () {
    Storage::fake('public');
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);

    $this->actingAs($cashier)->post('/shift/movements', [
        'direction' => 'out', 'amount' => 9000, 'category_id' => galon()->id, 'receipt' => UploadedFile::fake()->image('nota.jpg', 300, 300),
    ])->assertSessionHasNoErrors();

    expect(CashMovement::query()->firstOrFail()->receipt_path)->not->toBeNull();
    $this->actingAs($cashier)->get('/shift')->assertInertia(fn ($p) => $p->where('shift.movements.0.receipt', fn ($v) => is_string($v) && $v !== '')->has('categories'));
});

it('still takes plain cash in with an account and a reason, and refuses a category on cash in', function () {
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    $account = Account::query()->where('type', 'equity')->firstOrFail();

    $this->actingAs($cashier)->post('/shift/movements', ['direction' => 'in', 'amount' => 50000, 'reason' => 'tambah kembalian', 'account_id' => $account->id])->assertSessionHasNoErrors();
    expect(CashMovement::query()->count())->toBe(1)->and(Expense::query()->count())->toBe(0);

    $this->actingAs($cashier)->post('/shift/movements', ['direction' => 'in', 'amount' => 1000, 'category_id' => galon()->id])->assertSessionHas('error');
    $this->actingAs($cashier)->post('/shift/movements', ['direction' => 'out', 'amount' => 1000])->assertSessionHas('error');
    expect(CashMovement::query()->count())->toBe(1);
});

it('does not let the Pengeluaran page book drawer money, which would break the drawer count', function () {
    $manager = userWithRole('manager');
    $drawer = SystemAccount::CashDrawer->resolve();

    $this->actingAs($manager)->get('/admin/expenses')->assertInertia(fn ($p) => $p->where('sources', fn ($s) => collect($s)->doesntContain('id', $drawer->id)));
    $this->actingAs($manager)->post('/admin/expenses', ['date' => now()->toDateString(), 'category_id' => galon()->id, 'amount' => 5000, 'source_account_id' => $drawer->id])->assertSessionHas('error');
    expect(Expense::query()->count())->toBe(0);
});

it('lists the drawer report per shift with spending by category, for people who may see it', function () {
    $cashier = userWithRole('cashier');
    $shift = openShiftFor($cashier, 100000);
    pay(placeOrder($cashier), $cashier);
    $this->actingAs($cashier)->post('/shift/movements', ['direction' => 'out', 'amount' => 20000, 'category_id' => galon()->id])->assertSessionHasNoErrors();
    $this->actingAs($cashier)->post('/shift/movements', ['direction' => 'out', 'amount' => 5000, 'category_id' => galon()->id])->assertSessionHasNoErrors();
    app(CloseShiftAction::class)->handle($shift->fresh(), $cashier, 119000, 0, null, null, 'cl1');

    $today = now()->toDateString();
    $this->actingAs(userWithRole('manager'))->get("/admin/cash-ledger?from={$today}&to={$today}")->assertOk()->assertInertia(fn ($p) => $p->component('admin/cash-ledger')
        ->where('totals.cash_out', 25000)->where('totals.cash_sales', 44000)->where('shifts.0.expected', 119000)->where('shifts.0.difference', 0)
        ->where('by_category.0.count', 2)->where('by_category.0.total', 25000)->has('shifts.0.movements', 2));

    $this->actingAs(userWithRole('manager'))->get("/admin/cash-ledger/export?from={$today}&to={$today}&format=xlsx")->assertOk()->assertDownload();
    $this->actingAs(userWithRole('manager'))->get("/admin/cash-ledger/export?from={$today}&to={$today}&format=pdf")->assertOk();
    $this->actingAs(userWithRole('waiter'))->get('/admin/cash-ledger')->assertForbidden();
    $this->actingAs($cashier)->get('/admin/cash-ledger')->assertForbidden();
});

it('counts a drawer spend once in the daily expense list', function () {
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    $this->actingAs($cashier)->post('/shift/movements', ['direction' => 'out', 'amount' => 12000, 'category_id' => galon()->id])->assertSessionHasNoErrors();

    $list = app(ReportDelivery::class)->expenses(now()->toDateString());
    expect($list['items'])->toHaveCount(1)->and($list['total'])->toBe(12000)->and($list['items'][0]['source'])->toBe('Kas laci');
});
