<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Models\Account;
use App\Domain\Expense\Actions\RecordExpenseAction;
use App\Domain\Expense\Models\Expense;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Settings\OutletContext;
use App\Http\Controllers\Controller;
use App\Support\BusinessDate;
use App\Support\ImageOptimizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    public function __construct(private OutletContext $outlet) {}

    public function index(Request $request): Response
    {
        $id = $this->outlet->id();
        $from = $request->input('from', date('Y-m-01'));
        $to = $request->input('to', BusinessDate::for());

        return Inertia::render('admin/expenses', [
            'filters' => compact('from', 'to'),
            'expenses' => Expense::query()->where('outlet_id', $id)->whereBetween('date', [$from, $to])->with('category')->latest('date')->latest('id')->get()->map(fn ($e) => [
                'id' => $e->id, 'date' => $e->date->format('Y-m-d'), 'category' => $e->category->name, 'amount' => $e->amount, 'note' => $e->note,
                'receipt' => $e->receipt_path ? \Storage::url($e->receipt_path) : null,
            ]),
            'categories' => ExpenseCategory::query()->where('outlet_id', $id)->orderBy('name')->get(['id', 'name']),
            'sources' => Account::query()->where('outlet_id', $id)->where('type', 'asset')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'today' => BusinessDate::for(),
        ]);
    }

    public function store(Request $request, RecordExpenseAction $action, ImageOptimizer $images): RedirectResponse
    {
        $data = $request->validate([
            'date' => 'required|date', 'category_id' => 'required|integer', 'amount' => 'required|integer|min:1',
            'source_account_id' => 'required|integer', 'note' => 'nullable|string|max:200', 'receipt' => 'nullable|image|max:8192',
        ]);
        $receipt = $request->hasFile('receipt') ? $images->store($request->file('receipt'), 'receipts') : null;
        $action->handle($this->actor($request), $data['date'], (int) $data['category_id'], (int) $data['amount'], (int) $data['source_account_id'], $data['note'] ?? null, $receipt);

        return back()->with('success', 'Pengeluaran dicatat.');
    }

    /** Expense categories are owner-managed; each one maps to an expense account. */
    public function saveCategory(Request $request, ?ExpenseCategory $category = null): RedirectResponse
    {
        $id = $this->outlet->id();
        $data = $request->validate(['name' => 'required|string|max:60', 'account_id' => ['required', Rule::exists('accounts', 'id')->where('outlet_id', $id)->where('type', 'expense')]]);
        $category ? $category->update($data) : ExpenseCategory::create($data + ['outlet_id' => $id]);

        return back()->with('success', 'Kategori beban disimpan.');
    }
}
