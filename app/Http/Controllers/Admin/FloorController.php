<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Floor\Models\Area;
use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\Models\Order;
use App\Domain\Settings\Models\Outlet;
use App\Domain\Settings\OutletContext;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FloorController extends Controller
{
    public function __construct(private OutletContext $outlet) {}

    public function index(): Response
    {
        return Inertia::render('admin/tables', [
            'areas' => Area::query()->where('outlet_id', $this->outlet->id())->orderBy('sort')->with('tables')->get()->map(fn ($a) => [
                'id' => $a->id, 'name' => $a->name,
                'tables' => $a->tables->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'capacity' => $t->capacity, 'qr_enabled' => $t->qr_enabled, 'status' => $t->status->value, 'qr_url' => route('qr.show', $t->qr_token)])->all(),
            ]),
        ]);
    }

    public function saveArea(Request $request, ?Area $area = null): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:60']);
        $area ? $area->update($data) : Area::create($data + ['outlet_id' => $this->outlet->id()]);

        return back()->with('success', 'Area disimpan.');
    }

    public function saveTable(Request $request, ?Table $table = null): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:40', 'capacity' => 'required|integer|min:1|max:50', 'area_id' => 'required|exists:areas,id']);
        $table ? $table->update($data) : Table::create($data + ['outlet_id' => $this->outlet->id()]);

        return back()->with('success', 'Meja disimpan.');
    }

    public function deleteTable(Table $table): RedirectResponse
    {
        if (Order::query()->where('table_id', $table->id)->whereIn('status', ['open', 'partially_paid'])->exists()) {
            return back()->with('error', 'Meja masih punya order terbuka.');
        }
        $table->delete();

        return back()->with('success', 'Meja dihapus.');
    }

    /** Leaked QR? Issue a new random token; the old one stops working at once. */
    public function regenerateToken(Table $table, Request $request): RedirectResponse
    {
        $table->regenerateToken();
        Audit::log('table.qr_regenerated', $table, ['table' => $table->name]);

        return back()->with('success', "QR {$table->name} dibuat ulang. Cetak QR yang baru.");
    }

    public function toggleQr(Table $table, Request $request): JsonResponse
    {
        abort_unless($this->actor($request)->can('manage-tables') || $this->actor($request)->can('receive-payment'), 403);
        $table->update(['qr_enabled' => $request->boolean('qr_enabled')]);
        Audit::log('table.qr_toggled', $table, ['enabled' => $table->qr_enabled]);

        return response()->json(['qr_enabled' => $table->qr_enabled]);
    }

    /** Print-ready PDF: one QR per table with the table name. */
    public function qrPdf(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $tables = Table::query()->where('outlet_id', $this->outlet->id())
            ->when($request->integer('area_id'), fn ($q, $a) => $q->where('area_id', $a))
            ->when($request->integer('table_id'), fn ($q, $t) => $q->whereKey($t))
            ->orderBy('area_id')->orderBy('name')->get();

        $writer = new Writer(new ImageRenderer(new RendererStyle(360, 1), new SvgImageBackEnd));
        $cards = $tables->map(fn ($t) => [
            'name' => $t->name,
            'svg' => 'data:image/svg+xml;base64,'.base64_encode($writer->writeString(route('qr.show', $t->qr_token))),
        ]);

        return Pdf::loadView('pdf.qr-tables', ['cards' => $cards, 'outlet' => Outlet::find($this->outlet->id())?->name])
            ->setPaper('a4')->stream('qr-meja.pdf');
    }
}
