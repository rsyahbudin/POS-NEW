<?php

namespace App\Domain\Reporting;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Settings\OutletContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/** Query object for all accounting reports. Balances are computed from posted journal lines only. */
class AccountingReports
{
    public function __construct(private OutletContext $outlet) {}

    /**
     * Net balance per account (debit-normal accounts: debit-credit; others credit-debit).
     *
     * @return Collection<int, AccountBalance>
     */
    public function balances(?string $from = null, ?string $to = null): Collection
    {
        $rows = DB::table('accounts')
            ->leftJoin('journal_lines', 'journal_lines.account_id', '=', 'accounts.id')
            ->leftJoin('journal_entries', function ($j) use ($from, $to) {
                $j->on('journal_entries.id', '=', 'journal_lines.journal_entry_id');
                if ($from) {
                    $j->where('journal_entries.date', '>=', $from);
                }
                if ($to) {
                    $j->where('journal_entries.date', '<=', $to);
                }
            })
            ->where('accounts.outlet_id', $this->outlet->id())
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type')
            ->orderBy('accounts.code')
            ->selectRaw('accounts.id, accounts.code, accounts.name, accounts.type,
                COALESCE(SUM(CASE WHEN journal_entries.id IS NOT NULL THEN journal_lines.debit END),0) as debit,
                COALESCE(SUM(CASE WHEN journal_entries.id IS NOT NULL THEN journal_lines.credit END),0) as credit')
            ->get();

        return $rows->map(fn (stdClass $r) => new AccountBalance((int) $r->id, (string) $r->code, (string) $r->name, (string) $r->type, (int) $r->debit, (int) $r->credit))->values();
    }

    /** @return array{revenue: Collection<int, AccountBalance>, expense: Collection<int, AccountBalance>, total_revenue: int, total_expense: int, net_profit: int} */
    public function profitLoss(string $from, string $to): array
    {
        $b = $this->balances($from, $to)->filter(fn (AccountBalance $r) => $r->debit + $r->credit > 0);
        $revenue = $b->where('type', 'revenue')->values();
        $expense = $b->where('type', 'expense')->values();

        return [
            'revenue' => $revenue, 'expense' => $expense,
            'total_revenue' => (int) $revenue->sum('balance'), 'total_expense' => (int) $expense->sum('balance'),
            'net_profit' => (int) $revenue->sum('balance') - (int) $expense->sum('balance'),
        ];
    }

    /** @return array{assets: Collection<int, AccountBalance>, liabilities: Collection<int, AccountBalance>, equity: Collection<int, AccountBalance>, current_earnings: int, total_assets: int, total_liabilities_equity: int} */
    public function balanceSheet(string $asOf): array
    {
        $b = $this->balances(null, $asOf);
        $earnings = (int) $b->where('type', 'revenue')->sum('balance') - (int) $b->where('type', 'expense')->sum('balance');
        $assets = $b->where('type', 'asset')->values();
        $liabilities = $b->where('type', 'liability')->values();
        $equity = $b->where('type', 'equity')->values();

        return [
            'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity, 'current_earnings' => $earnings,
            'total_assets' => (int) $assets->sum('balance'),
            'total_liabilities_equity' => (int) $liabilities->sum('balance') + (int) $equity->sum('balance') + $earnings,
        ];
    }

    /** @return array{opening: int, lines: list<array<string, mixed>>, closing: int} */
    public function ledger(int $accountId, string $from, string $to): array
    {
        $account = DB::table('accounts')->where('outlet_id', $this->outlet->id())->where('id', $accountId)->first()
            ?? throw new InvalidArgumentException('Akun tidak ditemukan.');
        $debitNormal = AccountType::from($account->type)->isDebitNormal();
        $sign = fn (int $d, int $c): int => $debitNormal ? $d - $c : $c - $d;

        $opening = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $accountId)->where('journal_entries.date', '<', $from)
            ->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->sole();
        $running = $sign((int) $opening->d, (int) $opening->c);
        $start = $running;

        $lines = [];
        foreach (DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $accountId)->whereBetween('journal_entries.date', [$from, $to])
            ->orderBy('journal_entries.date')->orderBy('journal_entries.id')
            ->select('journal_entries.id as entry_id', 'journal_entries.date', 'journal_entries.memo', 'journal_lines.debit', 'journal_lines.credit')->get() as $l) {
            $running += $sign((int) $l->debit, (int) $l->credit);
            $lines[] = ['entry_id' => $l->entry_id, 'date' => $l->date, 'memo' => $l->memo, 'debit' => (int) $l->debit, 'credit' => (int) $l->credit, 'balance' => $running];
        }

        return ['opening' => $start, 'lines' => $lines, 'closing' => $running];
    }

    /**
     * Cash-flow per cash/bank account (asset accounts that back a payment method, plus Kas Laci).
     *
     * @return Collection<int, CashFlowRow>
     */
    public function cashFlow(string $from, string $to): Collection
    {
        $ids = DB::table('payment_methods')->where('outlet_id', $this->outlet->id())->pluck('account_id')
            ->merge(DB::table('accounts')->where('outlet_id', $this->outlet->id())->where('system_key', 'cash_drawer')->pluck('id'))->unique()->values();

        return $this->balances()->filter(fn (AccountBalance $a) => $ids->contains($a->id))->values()->map(function (AccountBalance $a) use ($from, $to) {
            $l = $this->ledger($a->id, $from, $to);

            return new CashFlowRow($a->id, $a->code, $a->name, $l['opening'], (int) collect($l['lines'])->sum('debit'), (int) collect($l['lines'])->sum('credit'), $l['closing']);
        });
    }

    /** @return Collection<int, stdClass> journal entries with their `lines` collection attached */
    public function journals(string $from, string $to, int $limit = 200): Collection
    {
        return DB::table('journal_entries')->where('outlet_id', $this->outlet->id())->whereBetween('date', [$from, $to])
            ->orderByDesc('date')->orderByDesc('id')->limit($limit)->get()
            ->each(function (stdClass $e) {
                $e->lines = DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
                    ->where('journal_entry_id', $e->id)->select('accounts.code', 'accounts.name', 'journal_lines.debit', 'journal_lines.credit')->get();
            });
    }
}
