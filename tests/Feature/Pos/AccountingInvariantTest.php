<?php

use App\Domain\Accounting\Actions\ClosePeriodAction;
use App\Domain\Accounting\Actions\PostJournalAction;
use App\Domain\Accounting\Actions\PostManualJournalAction;
use App\Domain\Accounting\Actions\ReverseJournalAction;
use App\Domain\Accounting\Exceptions\AccountingException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\SystemAccount;
use App\Domain\Expense\Actions\RecordExpenseAction;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Floor\Models\Table;
use App\Domain\Payment\Actions\RefundPaymentAction;
use App\Domain\Payment\Actions\SplitBillAction;
use App\Domain\Pricing\Actions\ApplyDiscountAction;
use App\Domain\Reporting\AccountingReports;
use App\Domain\Reporting\SalesReports;
use App\Domain\Settings\OutletContext;
use App\Support\BusinessDate;
use Illuminate\Database\QueryException;

function acct(string $code): int
{
    return Account::query()->where('code', $code)->value('id');
}

it('rejects unbalanced journals in the Action', function () {
    expect(fn () => app(PostJournalAction::class)->handle('2026-10-20', 'x', [
        ['account_id' => acct('1-1100'), 'debit' => 100], ['account_id' => acct('3-1000'), 'credit' => 90],
    ]))->toThrow(AccountingException::class, 'seimbang');
});

it('enforces balance at the database too (constraint trigger)', function () {
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $period = DB::table('periods')->insertGetId(['outlet_id' => app(OutletContext::class)->id(), 'month' => 10, 'year' => 2026, 'created_at' => now(), 'updated_at' => now()]);
    $entry = DB::table('journal_entries')->insertGetId(['outlet_id' => app(OutletContext::class)->id(), 'date' => '2026-10-20', 'period_id' => $period, 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => DB::table('journal_lines')->insert(['journal_entry_id' => $entry, 'account_id' => acct('1-1100'), 'debit' => 500, 'credit' => 0, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class, 'unbalanced');
});

it('rejects a line that is both debit and credit (CHECK constraint) and negative money', function () {
    $period = DB::table('periods')->insertGetId(['outlet_id' => app(OutletContext::class)->id(), 'month' => 10, 'year' => 2026, 'created_at' => now(), 'updated_at' => now()]);
    $entry = DB::table('journal_entries')->insertGetId(['outlet_id' => app(OutletContext::class)->id(), 'date' => '2026-10-20', 'period_id' => $period, 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    expect(fn () => DB::table('journal_lines')->insert(['journal_entry_id' => $entry, 'account_id' => acct('1-1100'), 'debit' => 5, 'credit' => 5, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('products')->where('name', 'Americano')->update(['base_price' => -1]))->toThrow(QueryException::class);
});

it('keeps posted journals immutable: corrections go through a reversing entry', function () {
    $owner = userWithRole('owner');
    $entry = app(PostManualJournalAction::class)->handle($owner, BusinessDate::for(), 'saldo awal', [
        ['account_id' => acct('1-1100'), 'debit' => 500000], ['account_id' => acct('3-1000'), 'credit' => 500000],
    ]);
    $reversal = app(ReverseJournalAction::class)->handle($entry, 'koreksi', $owner->id);

    expect($entry->fresh()->reversed_by)->toBe($reversal->id)->and(accountBalance('cash_drawer'))->toBe(0);
    expect(fn () => app(ReverseJournalAction::class)->handle($entry->fresh(), 'lagi', $owner->id))->toThrow(AccountingException::class);
    assertJournalsBalanced();
});

it('refuses new journals in a closed period', function () {
    app(ClosePeriodAction::class)->handle(userWithRole('owner'), (int) date('Y'), (int) date('n'));
    expect(fn () => app(PostManualJournalAction::class)->handle(userWithRole('owner'), BusinessDate::for(), 'x', [
        ['account_id' => acct('1-1100'), 'debit' => 1], ['account_id' => acct('3-1000'), 'credit' => 1],
    ]))->toThrow(AccountingException::class, 'ditutup');
});

it('journals expenses: debit expense, credit the funding account', function () {
    $manager = userWithRole('manager');
    $cat = ExpenseCategory::query()->where('name', 'Listrik & Air')->first();
    app(RecordExpenseAction::class)->handle($manager, BusinessDate::for(), $cat->id, 250000, SystemAccount::CashDrawer->resolve()->id, 'token listrik');

    expect((int) DB::table('journal_lines')->where('account_id', $cat->account_id)->sum('debit'))->toBe(250000)->and(accountBalance('cash_drawer'))->toBe(-250000);
    assertJournalsBalanced();
});

/**
 * PRD section 10 invariants on a messy day: tax, rounding, discount, split bill, refund, expense.
 */
it('holds all accounting invariants after a mixed day', function () {
    enableTax();
    setting('pricing.rounding', 100);
    $cashier = userWithRole('cashier');
    $manager = userWithRole('manager');
    openShiftFor($cashier, 0);
    openShiftFor($manager, 0);
    $tables = Table::query()->orderBy('id')->get();

    // 1) plain, cash
    $o1 = placeOrder(tableId: $tables[0]->id, items: [['product_id' => product('Nasi Goreng')->id, 'qty' => 3]]);
    $p1 = pay($o1, amount: 200000);

    // 2) discounted + QRIS
    $o2 = placeOrder(tableId: $tables[1]->id, items: [['product_id' => product('Americano')->id, 'qty' => 5], ['product_id' => product('Croissant')->id, 'qty' => 2]]);
    app(ApplyDiscountAction::class)->handle($o2, $manager, 'percent', 1500, 'event');
    pay($o2->fresh(), type: 'qris');

    // 3) split per amount, mixed methods
    $o3 = placeOrder(tableId: $tables[2]->id, items: [['product_id' => product('Nasi Goreng')->id, 'qty' => 2], ['product_id' => product('Americano')->id, 'qty' => 1]]);
    $o3 = $o3->fresh();
    $half = intdiv($o3->grand_total, 2);
    $bills = app(SplitBillAction::class)->handle($o3, 'amount', ['parts' => [['amount' => $half], ['amount' => $o3->grand_total - $half]]], $cashier);
    pay($bills[0], type: 'transfer');
    pay($bills[1], type: 'cash');

    // 4) refund part of order 1
    app(RefundPaymentAction::class)->handle($p1, $manager, 30000, 'komplain', 'rf-1');

    // 5) expense
    app(RecordExpenseAction::class)->handle($manager, BusinessDate::for(), ExpenseCategory::query()->first()->id, 40000, SystemAccount::CashDrawer->resolve()->id);

    // Invariant 1: every entry balances
    assertJournalsBalanced();

    $today = BusinessDate::for();
    $pl = app(AccountingReports::class)->profitLoss($today, $today);
    $sales = app(SalesReports::class)->summary($today, $today);

    $taxPayable = (int) app(AccountingReports::class)->balances($today, $today)->firstWhere('code', '2-1100')->balance;
    expect($sales['orders'])->toBe(3);

    // Invariant 3: revenue in P&L = grand total of paid orders - tax - refunds.
    // Rounding differences sit in "Selisih Pembulatan" (an expense-type account, credit = gain), so add it back.
    $rounding = (int) ($pl['expense']->firstWhere('code', '5-9200')->balance ?? 0);
    expect($pl['total_revenue'] - $rounding)->toBe($sales['gross'] - $taxPayable - $sales['refunds']);

    // Balance sheet always balances: assets = liabilities + equity + current earnings
    $bs = app(AccountingReports::class)->balanceSheet($today);
    expect($bs['total_assets'])->toBe($bs['total_liabilities_equity']);
});

it('computes P&L, ledger and cash flow consistently', function () {
    openShiftFor(userWithRole('cashier'), 0);
    pay(placeOrder());
    $today = BusinessDate::for();
    $r = app(AccountingReports::class);

    expect($r->profitLoss($today, $today)['net_profit'])->toBe(44000);
    $ledger = $r->ledger(SystemAccount::CashDrawer->resolve()->id, $today, $today);
    expect($ledger['closing'])->toBe(44000)->and($ledger['lines'])->toHaveCount(1);
    expect($r->cashFlow($today, $today)->firstWhere('code', '1-1100')->inflow)->toBe(44000);
});
