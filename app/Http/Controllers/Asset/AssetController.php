<?php

namespace App\Http\Controllers\Asset;

use App\Domain\Asset\Models\Asset;
use App\Domain\Asset\Models\AssetLog;
use App\Domain\Settings\OutletContext;
use App\Exports\ReportSheet;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\ReportFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Register of the outlet's equipment: what exists, where, in what condition, and its service history. */
class AssetController extends Controller
{
    public function __construct(private OutletContext $outlet) {}

    public function index(Request $request): Response
    {
        $assets = Asset::query()->where('outlet_id', $this->outlet->id())->with('logs')->orderBy('category')->orderBy('name')->get();
        $users = User::query()->pluck('name', 'id');

        return Inertia::render('assets/index', [
            'assets' => $assets->map(fn (Asset $a) => [
                'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'category' => $a->category, 'brand' => $a->brand, 'model' => $a->model, 'serial' => $a->serial,
                'quantity' => $a->quantity, 'purchased_on' => $a->purchased_on?->toDateString(), 'purchase_price' => $a->purchase_price, 'location' => $a->location,
                'condition' => $a->condition, 'notes' => $a->notes,
                'logs' => $a->logs->sortByDesc('id')->take(15)->map(fn (AssetLog $l) => [
                    'id' => $l->id, 'type' => $l->type, 'happened_on' => $l->happened_on->toDateString(), 'cost' => $l->cost, 'note' => $l->note, 'user' => $users[$l->user_id] ?? null,
                ])->values()->all(),
            ])->values()->all(),
            'summary' => [
                'items' => (int) $assets->where('condition', '!=', 'retired')->sum('quantity'),
                'value' => (int) $assets->where('condition', '!=', 'retired')->sum(fn (Asset $a) => $a->quantity * $a->purchase_price),
                'attention' => $assets->whereIn('condition', ['needs_service', 'broken'])->count(),
            ],
            'conditions' => collect(Asset::CONDITIONS)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'logTypes' => collect(AssetLog::TYPES)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'categories' => $assets->pluck('category')->filter()->unique()->values()->all(),
            'can' => ['manage' => $this->actor($request)->can('manage-assets')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['code'] = ($data['code'] ?? null) ?: $this->nextCode();
        $asset = Asset::create($data + ['outlet_id' => $this->outlet->id()]);
        Audit::log('asset.created', $asset, ['name' => $asset->name, 'code' => $asset->code]);

        return back()->with('success', 'Aset ditambahkan.');
    }

    public function update(Request $request, Asset $asset): RedirectResponse
    {
        $this->own($asset);
        $data = $this->validated($request, $asset);
        $data['code'] = ($data['code'] ?? null) ?: $asset->code;
        $asset->update($data);
        Audit::log('asset.updated', $asset, ['name' => $asset->name]);

        return back()->with('success', 'Aset disimpan.');
    }

    public function destroy(Asset $asset): RedirectResponse
    {
        $this->own($asset);
        $asset->delete();
        Audit::log('asset.deleted', null, ['name' => $asset->name, 'code' => $asset->code]);

        return back()->with('success', 'Aset dihapus.');
    }

    /** Service, repair, move or note; can also update the asset's condition and location. */
    public function log(Request $request, Asset $asset): RedirectResponse
    {
        $this->own($asset);
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(AssetLog::TYPES))], 'happened_on' => 'required|date', 'cost' => 'nullable|integer|min:0', 'note' => 'nullable|string|max:200',
            'condition' => ['nullable', Rule::in(array_keys(Asset::CONDITIONS))], 'location' => 'nullable|string|max:60',
        ]);
        $asset->logs()->create(['type' => $data['type'], 'happened_on' => $data['happened_on'], 'cost' => $data['cost'] ?? 0, 'note' => $data['note'] ?? null, 'user_id' => $this->actor($request)->id]);
        $changes = array_filter(['condition' => $data['condition'] ?? null, 'location' => $data['location'] ?? null]);
        if ($changes !== []) {
            $asset->update($changes);
        }
        Audit::log('asset.log', $asset, ['type' => $data['type'], 'cost' => $data['cost'] ?? 0]);

        return back()->with('success', 'Riwayat dicatat.');
    }

    public function export(Request $request): HttpResponse
    {
        $assets = Asset::query()->where('outlet_id', $this->outlet->id())->orderBy('category')->orderBy('name')->get();
        $rows = $assets->map(fn (Asset $a) => [
            $a->code, $a->name, $a->category ?? '', trim(($a->brand ?? '').' '.($a->model ?? '')), $a->serial ?? '', $a->quantity, $a->location ?? '',
            Asset::CONDITIONS[$a->condition] ?? $a->condition, $a->purchased_on?->translatedFormat('d M Y') ?? '', $a->purchase_price, $a->quantity * $a->purchase_price,
        ])->all();

        return ReportFile::download((string) $request->input('format', 'xlsx'), 'Daftar aset '.now()->toDateString(), [
            new ReportSheet('Daftar aset', 'Per '.now()->setTimezone('Asia/Jakarta')->translatedFormat('d M Y'), ['Kode', 'Nama', 'Kategori', 'Merek / model', 'No. seri', 'Jumlah', 'Lokasi', 'Kondisi', 'Tgl beli', 'Harga satuan', 'Nilai (Rp)'], $rows,
                ['Total', '', '', '', '', (int) $assets->sum('quantity'), '', '', '', '', (int) $assets->sum(fn (Asset $a) => $a->quantity * $a->purchase_price)]),
        ]);
    }

    private function own(Asset $asset): void
    {
        abort_unless($asset->outlet_id === $this->outlet->id(), 404);
    }

    private function nextCode(): string
    {
        $n = (int) Asset::query()->where('outlet_id', $this->outlet->id())->count() + 1;
        do {
            $code = sprintf('AST-%04d', $n++);
        } while (Asset::query()->where('outlet_id', $this->outlet->id())->where('code', $code)->exists());

        return $code;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Asset $asset = null): array
    {
        return $request->validate([
            'code' => ['nullable', 'string', 'max:20', Rule::unique('assets', 'code')->where('outlet_id', $this->outlet->id())->ignore($asset?->id)],
            'name' => 'required|string|max:100', 'category' => 'nullable|string|max:40', 'brand' => 'nullable|string|max:60', 'model' => 'nullable|string|max:60', 'serial' => 'nullable|string|max:60',
            'quantity' => 'required|integer|min:1|max:9999', 'purchased_on' => 'nullable|date', 'purchase_price' => 'nullable|integer|min:0', 'location' => 'nullable|string|max:60',
            'condition' => ['required', Rule::in(array_keys(Asset::CONDITIONS))], 'notes' => 'nullable|string|max:1000',
        ]) + ['purchase_price' => (int) $request->input('purchase_price', 0)];
    }
}
