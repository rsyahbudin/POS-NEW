<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Actions\ClosePeriodAction;
use App\Domain\Accounting\Actions\PostManualJournalAction;
use App\Domain\Accounting\Actions\ReverseJournalAction;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Period;
use App\Domain\Reporting\AccountingReports;
use App\Domain\Settings\OutletContext;
use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\BusinessDate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

/** Read pages need `view-accounting` (manager can read); every write needs `manage-accounting` (owner). */
class AccountingController extends Controller
{
    public function __construct(private AccountingReports $reports, private OutletContext $outlet) {}

    public function index(Request $request): Response
    {
        [$from, $to] = $this->range($request);
        $outletId = $this->outlet->id();

        return Inertia::render('admin/accounting', [
            'filters' => ['from' => $from, 'to' => $to, 'account_id' => $request->integer('account_id') ?: null],
            'accounts' => Account::query()->where('outlet_id', $outletId)->orderBy('code')->get(['id', 'code', 'name', 'type', 'is_system', 'is_active']),
            'balances' => $this->reports->balances($from, $to)->filter(fn ($r) => $r->debit + $r->credit > 0)->values(),
            'profitLoss' => $this->reports->profitLoss($from, $to),
            'balanceSheet' => $this->reports->balanceSheet($to),
            'cashFlow' => $this->reports->cashFlow($from, $to),
            'journals' => $this->reports->journals($from, $to),
            'ledger' => $request->integer('account_id') ? $this->reports->ledger($request->integer('account_id'), $from, $to) : null,
            'periods' => Period::query()->where('outlet_id', $outletId)->orderByDesc('year')->orderByDesc('month')->get(['id', 'year', 'month', 'closed_at']),
            'canManage' => $this->actor($request)->can('manage-accounting'),
        ]);
    }

    public function postJournal(Request $request, PostManualJournalAction $action): RedirectResponse
    {
        $data = $request->validate([
            'date' => 'required|date', 'memo' => 'required|string|max:200', 'lines' => 'required|array|min:2',
            'lines.*.account_id' => 'required|integer|exists:accounts,id', 'lines.*.debit' => 'nullable|integer|min:0', 'lines.*.credit' => 'nullable|integer|min:0',
        ]);
        $action->handle($this->actor($request), $data['date'], $data['memo'], $data['lines']);

        return back()->with('success', 'Jurnal diposting.');
    }

    public function reverse(JournalEntry $entry, Request $request, ReverseJournalAction $action): RedirectResponse
    {
        $data = $request->validate(['memo' => 'required|string|max:200']);
        $action->handle($entry, $data['memo'], $this->actor($request)->id, BusinessDate::for());
        Audit::log('journal.reversed', $entry, ['memo' => $data['memo']]);

        return back()->with('success', 'Jurnal pembalik dibuat.');
    }

    public function closePeriod(Request $request, ClosePeriodAction $action): RedirectResponse
    {
        $data = $request->validate(['year' => 'required|integer|min:2020', 'month' => 'required|integer|min:1|max:12']);
        $action->handle($this->actor($request), (int) $data['year'], (int) $data['month']);

        return back()->with('success', 'Periode ditutup.');
    }

    public function saveAccount(Request $request, ?Account $account = null): RedirectResponse
    {
        $outletId = $this->outlet->id();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('accounts', 'code')->where('outlet_id', $outletId)->ignore($account?->id)],
            'name' => 'required|string|max:80',
            'type' => 'required|in:asset,liability,equity,revenue,expense', 'is_active' => 'boolean',
        ]);
        if ($account?->is_system) {
            unset($data['type'], $data['code']); // system accounts keep identity; automation depends on them
        }
        $account ? $account->update($data) : Account::create($data + ['outlet_id' => $outletId]);

        return back()->with('success', 'Akun disimpan.');
    }

    public function export(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        [$from, $to] = $this->range($request);
        $report = $request->input('report', 'profit_loss');
        $format = $request->input('format', 'xlsx');

        [$title, $headings, $rows] = match ($report) {
            'journal' => ['Jurnal Umum', ['Tanggal', 'Memo', 'Akun', 'Debit', 'Kredit'], $this->journalRows($from, $to)],
            'ledger' => ['Buku Besar', ['Tanggal', 'Memo', 'Debit', 'Kredit', 'Saldo'], collect($this->reports->ledger($request->integer('account_id'), $from, $to)['lines'])->map(fn ($l) => [$l['date'], $l['memo'], $l['debit'], $l['credit'], $l['balance']])->all()],
            'balance_sheet' => ['Neraca Sederhana', ['Akun', 'Saldo'], $this->balanceSheetRows($to)],
            'cash_flow' => ['Arus Kas', ['Akun', 'Saldo awal', 'Masuk', 'Keluar', 'Saldo akhir'], $this->reports->cashFlow($from, $to)->map(fn ($a) => [$a->name, $a->opening, $a->inflow, $a->outflow, $a->closing])->all()],
            default => ['Laba Rugi', ['Akun', 'Jumlah'], $this->profitLossRows($from, $to)],
        };

        $name = str_replace(' ', '-', strtolower($title))."-{$from}-{$to}";

        return $format === 'pdf'
            ? Pdf::loadView('pdf.table', ['title' => $title, 'subtitle' => "{$from} s/d {$to}", 'headings' => $headings, 'rows' => $rows])->download("{$name}.pdf")
            : Excel::download(new ArrayExport($headings, $rows), "{$name}.xlsx");
    }

    /** @return array<int, array<int, mixed>> */
    private function journalRows(string $from, string $to): array
    {
        $rows = [];
        foreach ($this->reports->journals($from, $to, 5000) as $entry) {
            foreach ($entry->lines as $line) {
                $rows[] = [$entry->date, $entry->memo, "{$line->code} {$line->name}", (int) $line->debit, (int) $line->credit];
            }
        }

        return $rows;
    }

    /** @return list<list<scalar>> */
    private function profitLossRows(string $from, string $to): array
    {
        $pl = $this->reports->profitLoss($from, $to);
        $rows = [['PENDAPATAN', '']];
        foreach ($pl['revenue'] as $r) {
            $rows[] = ["{$r->code} {$r->name}", $r->balance];
        }
        $rows[] = ['Total pendapatan', $pl['total_revenue']];
        $rows[] = ['BEBAN', ''];
        foreach ($pl['expense'] as $r) {
            $rows[] = ["{$r->code} {$r->name}", $r->balance];
        }
        $rows[] = ['Total beban', $pl['total_expense']];
        $rows[] = ['LABA BERSIH', $pl['net_profit']];

        return $rows;
    }

    /** @return list<list<scalar>> */
    private function balanceSheetRows(string $asOf): array
    {
        $bs = $this->reports->balanceSheet($asOf);
        $rows = [['ASET', '']];
        foreach ($bs['assets'] as $r) {
            $rows[] = ["{$r->code} {$r->name}", $r->balance];
        }
        $rows[] = ['Total aset', $bs['total_assets']];
        $rows[] = ['KEWAJIBAN', ''];
        foreach ($bs['liabilities'] as $r) {
            $rows[] = ["{$r->code} {$r->name}", $r->balance];
        }
        $rows[] = ['EKUITAS', ''];
        foreach ($bs['equity'] as $r) {
            $rows[] = ["{$r->code} {$r->name}", $r->balance];
        }
        $rows[] = ['Laba berjalan', $bs['current_earnings']];
        $rows[] = ['Total kewajiban + ekuitas', $bs['total_liabilities_equity']];

        return $rows;
    }

    /** @return array{0: string, 1: string} */
    private function range(Request $request): array
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        return [$request->input('from', date('Y-m-01')), $request->input('to', BusinessDate::for())];
    }
}
