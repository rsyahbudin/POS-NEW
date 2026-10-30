<?php

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\SystemAccount;
use App\Domain\Expense\FixedExpenseService;
use App\Domain\Expense\Models\Expense;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\FixedExpense;
use App\Domain\Expense\Models\FixedExpenseBill;
use App\Domain\Notification\Models\AppNotification;
use App\Domain\Settings\OutletContext;
use Carbon\CarbonImmutable;

function bankAccount(): Account
{
    return Account::query()->where('type', 'asset')->where(fn ($q) => $q->whereNull('system_key')->orWhere('system_key', '!=', SystemAccount::CashDrawer->value))->firstOrFail();
}

function rent(int $due = 5): FixedExpense
{
    return FixedExpense::create([
        'outlet_id' => app(OutletContext::class)->id(), 'name' => 'Sewa ruko', 'expense_category_id' => ExpenseCategory::query()->firstOrFail()->id,
        'amount' => 5000000, 'due_day' => $due, 'source_account_id' => bankAccount()->id, 'starts_on' => now()->startOfMonth()->toDateString(), 'is_active' => true,
    ]);
}

it('creates a fixed cost and its bill for this month, and clamps a day-31 due date in short months', function () {
    $manager = userWithRole('manager');
    $cat = ExpenseCategory::query()->firstOrFail();

    $this->actingAs($manager)->post('/admin/fixed-expenses', ['name' => 'Gaji', 'expense_category_id' => $cat->id, 'amount' => 3000000, 'due_day' => 31, 'source_account_id' => bankAccount()->id])
        ->assertSessionHasNoErrors()->assertSessionHas('success');
    expect(FixedExpenseBill::query()->count())->toBe(1);

    $cost = FixedExpense::query()->firstOrFail();
    $feb = CarbonImmutable::parse('2027-02-10');
    app(FixedExpenseService::class)->generate($cost->outlet_id, $feb);
    expect(FixedExpenseBill::query()->whereDate('period', '2027-02-01')->firstOrFail()->due_date->toDateString())->toBe('2027-02-28');
    // never twice for the same month
    expect(app(FixedExpenseService::class)->generate($cost->outlet_id, $feb))->toBe(0);
});

it('pays a bill: one expense, one journal against the chosen account, bill marked paid', function () {
    $manager = userWithRole('manager');
    $cost = rent();
    $bill = FixedExpenseBill::query()->firstOrCreate(['fixed_expense_id' => $cost->id, 'period' => now()->startOfMonth()->toDateString()], ['outlet_id' => $cost->outlet_id, 'due_date' => now()->startOfMonth()->addDays(4)->toDateString(), 'amount' => $cost->amount]);

    $this->actingAs($manager)->post("/admin/fixed-expense-bills/{$bill->id}/pay", ['date' => now()->toDateString(), 'amount' => 5100000, 'source_account_id' => bankAccount()->id, 'note' => 'naik'])
        ->assertSessionHasNoErrors()->assertSessionHas('success');

    $bill->refresh();
    expect($bill->status)->toBe('paid')->and($bill->amount)->toBe(5100000)->and($bill->expense_id)->not->toBeNull();
    expect(Expense::query()->count())->toBe(1)->and(Expense::query()->firstOrFail()->amount)->toBe(5100000)->and(JournalEntry::query()->where('memo', 'like', 'Pengeluaran:%')->count())->toBe(1);

    // a second payment of the same bill is refused and books nothing
    $this->actingAs($manager)->post("/admin/fixed-expense-bills/{$bill->id}/pay", ['date' => now()->toDateString(), 'amount' => 5100000, 'source_account_id' => bankAccount()->id])->assertSessionHas('error');
    expect(Expense::query()->count())->toBe(1);
});

it('refuses to pay a fixed cost from the cash drawer and lets a bill be skipped', function () {
    $manager = userWithRole('manager');
    $cost = rent();
    $bill = FixedExpenseBill::query()->create(['outlet_id' => $cost->outlet_id, 'fixed_expense_id' => $cost->id, 'period' => now()->startOfMonth()->toDateString(), 'due_date' => now()->toDateString(), 'amount' => 1000]);

    $this->actingAs($manager)->post("/admin/fixed-expense-bills/{$bill->id}/pay", ['date' => now()->toDateString(), 'amount' => 1000, 'source_account_id' => SystemAccount::CashDrawer->resolve()->id])->assertSessionHas('error');
    expect($bill->fresh()->status)->toBe('unpaid');

    $this->actingAs($manager)->post("/admin/fixed-expense-bills/{$bill->id}/skip")->assertSessionHas('success');
    expect($bill->fresh()->status)->toBe('skipped')->and(Expense::query()->count())->toBe(0);
    $this->actingAs($manager)->post("/admin/fixed-expense-bills/{$bill->id}/skip")->assertSessionHas('error');
});

it('reminds only when switched on, for bills due soon or late, and clears the reminder once paid', function () {
    $this->travelTo(now()->startOfMonth()->addDays(9)->setTime(8, 0));
    $manager = userWithRole('manager');
    $cost = rent(11);            // today is the 10th: due tomorrow
    $outletId = $cost->outlet_id;

    $this->artisan('fixed-expenses:remind')->assertSuccessful();
    expect(FixedExpenseBill::query()->count())->toBe(1)->and(AppNotification::query()->count())->toBe(0);

    setting('fixed_expense.remind', true);
    setting('fixed_expense.remind_days', 3);
    $this->artisan('fixed-expenses:remind')->assertSuccessful();
    $this->artisan('fixed-expenses:remind')->assertSuccessful();
    $n = AppNotification::query()->where('kind', 'fixed_expense.due')->get();
    expect($n)->toHaveCount(1)->and($n[0]->title)->toContain('1 hari lagi')->and($n[0]->permissions)->toBe(['manage-fixed-expenses']);

    $bill = FixedExpenseBill::query()->firstOrFail();
    app(FixedExpenseService::class)->pay($bill, $manager, now()->toDateString(), 5000000, bankAccount()->id);
    expect(AppNotification::query()->where('outlet_id', $outletId)->whereNull('resolved_at')->count())->toBe(0);
});

it('shows the page only to people with the permission, and edits a cost without touching paid bills', function () {
    $cost = rent();
    $manager = userWithRole('manager');
    $this->actingAs($manager)->get('/admin/fixed-expenses')->assertOk()->assertInertia(fn ($p) => $p->component('admin/fixed-expenses')->has('bills', 1)->has('costs', 1)->where('bills.0.status', 'unpaid'));
    $this->actingAs(userWithRole('cashier'))->get('/admin/fixed-expenses')->assertForbidden();
    $this->actingAs(userWithRole('waiter'))->post('/admin/fixed-expenses', [])->assertForbidden();

    $this->actingAs($manager)->put("/admin/fixed-expenses/{$cost->id}", ['name' => 'Sewa ruko', 'expense_category_id' => $cost->expense_category_id, 'amount' => 6000000, 'due_day' => 5, 'is_active' => true])->assertSessionHasNoErrors();
    expect(FixedExpenseBill::query()->firstOrFail()->amount)->toBe(6000000);
});
