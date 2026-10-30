<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Exceptions\AccountingException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\SystemAccount;
use App\Domain\Expense\FixedExpenseService;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\FixedExpense;
use App\Domain\Expense\Models\FixedExpenseBill;
use App\Domain\Settings\OutletContext;
use App\Http\Controllers\Controller;
use App\Support\BusinessDate;
use App\Support\ImageOptimizer;
use App\Support\MediaUrl;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Monthly fixed costs (rent, salary, electricity ...): the list of costs, and the bill of each month to pay or skip. */
class FixedExpenseController extends Controller
{
    public function __construct(private OutletContext $outlet, private FixedExpenseService $service) {}

    public function index(Request $request): Response
    {
        $id = $this->outlet->id();
        $request->validate(['month' => ['nullable', 'regex:/^\d{4}-\d{2}$/']]);
        $today = CarbonImmutable::parse(BusinessDate::for());
        $month = CarbonImmutable::parse(((string) $request->input('month', $today->format('Y-m'))).'-01')->startOfDay();
        if ($month->lte($today->addMonthNoOverflow()->endOfMonth())) {
            $this->service->generate($id, $month);
        }

        $bills = FixedExpenseBill::query()->where('outlet_id', $id)->whereDate('period', $month->toDateString())->with(['fixedExpense.category', 'expense'])->orderBy('due_date')->get();

        return Inertia::render('admin/fixed-expenses', [
            'month' => $month->format('Y-m'),
            'today' => $today->toDateString(),
            'bills' => $bills->map(fn (FixedExpenseBill $b) => [
                'id' => $b->id, 'name' => $b->fixedExpense->name, 'category' => $b->fixedExpense->category->name, 'due_date' => $b->due_date->toDateString(),
                'amount' => $b->amount, 'status' => $b->status, 'paid_on' => $b->paid_on?->toDateString(), 'source_account_id' => $b->fixedExpense->source_account_id,
                'receipt' => $b->expense?->receipt_path ? MediaUrl::public($b->expense->receipt_path) : null,
            ])->all(),
            'costs' => FixedExpense::query()->where('outlet_id', $id)->with('category:id,name')->orderByDesc('is_active')->orderBy('name')->get()->map(fn (FixedExpense $c) => [
                'id' => $c->id, 'name' => $c->name, 'category_id' => $c->expense_category_id, 'category' => $c->category->name, 'amount' => $c->amount, 'due_day' => $c->due_day,
                'source_account_id' => $c->source_account_id, 'note' => $c->note, 'is_active' => $c->is_active,
            ])->all(),
            'categories' => ExpenseCategory::query()->where('outlet_id', $id)->orderBy('name')->get(['id', 'name']),
            'sources' => $this->sources($id),
        ]);
    }

    public function save(Request $request, ?FixedExpense $cost = null): RedirectResponse
    {
        $id = $this->outlet->id();
        abort_if($cost !== null && $cost->outlet_id !== $id, 404);
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'expense_category_id' => ['required', Rule::exists('expense_categories', 'id')->where('outlet_id', $id)],
            'amount' => 'required|integer|min:0', 'due_day' => 'required|integer|min:1|max:31',
            'source_account_id' => ['nullable', Rule::exists('accounts', 'id')->where('outlet_id', $id)->where('type', 'asset')],
            'note' => 'nullable|string|max:200', 'is_active' => 'boolean',
        ]);
        if (isset($data['source_account_id']) && Account::query()->whereKey($data['source_account_id'])->where('system_key', SystemAccount::CashDrawer->value)->exists()) {
            return back()->with('error', 'Uang laci dicatat kasir di halaman Shift. Pilih rekening atau kas lain.');
        }
        if ($cost === null) {
            FixedExpense::create($data + ['outlet_id' => $id, 'starts_on' => BusinessDate::for(), 'is_active' => true]);
            $this->service->generate($id, CarbonImmutable::parse(BusinessDate::for()));
        } else {
            $cost->update($data);
            // A bill not yet paid follows the new default amount; paid ones keep what was paid.
            FixedExpenseBill::query()->where('fixed_expense_id', $cost->id)->where('status', 'unpaid')->update(['amount' => $data['amount']]);
        }

        return back()->with('success', 'Beban tetap disimpan.');
    }

    public function pay(Request $request, FixedExpenseBill $bill, ImageOptimizer $images): RedirectResponse
    {
        abort_unless($bill->outlet_id === $this->outlet->id(), 404);
        $data = $request->validate([
            'date' => 'required|date', 'amount' => 'required|integer|min:1', 'source_account_id' => 'required|integer|exists:accounts,id',
            'note' => 'nullable|string|max:150', 'receipt' => 'nullable|image|max:8192',
        ]);
        $receipt = $request->hasFile('receipt') ? $images->store($request->file('receipt'), 'receipts') : null;
        try {
            $this->service->pay($bill, $this->actor($request), $data['date'], (int) $data['amount'], (int) $data['source_account_id'], $data['note'] ?? null, $receipt);
        } catch (AccountingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Tagihan dibayar dan dicatat sebagai pengeluaran.');
    }

    public function skip(Request $request, FixedExpenseBill $bill): RedirectResponse
    {
        abort_unless($bill->outlet_id === $this->outlet->id(), 404);
        try {
            $this->service->skip($bill, $this->actor($request));
        } catch (AccountingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Tagihan dilewati.');
    }

    /** @return Collection<int, Account> */
    private function sources(int $outletId)
    {
        return Account::query()->where('outlet_id', $outletId)->where('type', 'asset')->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('system_key')->orWhere('system_key', '!=', SystemAccount::CashDrawer->value))->orderBy('code')->get(['id', 'code', 'name']);
    }
}
