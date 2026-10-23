<?php

namespace App\Http\Controllers\Pos;

use App\Domain\Catalog\Models\Modifier;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Ordering\Events\ProductAvailabilityChanged;
use App\Domain\Settings\OutletContext;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Realtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Quick "habis / stok" changes from the cashier screen: a product, its stock, or a single option (variant, sambal...). */
class SoldOutController extends Controller
{
    public function __construct(private OutletContext $outlet) {}

    public function stock(Product $product, Request $request): JsonResponse
    {
        $this->authorizeFor($request);
        abort_unless($product->outlet_id === $this->outlet->id(), 404);
        $data = $request->validate(['stock_qty' => 'required|integer|min:0|max:100000']);
        abort_if($product->stock_qty === null, 422, 'Menu ini tidak memakai stok.');

        $was = $product->stock_qty;
        $qty = (int) $data['stock_qty'];
        $available = $qty === 0 ? false : ($was === 0 ? true : $product->is_available);
        $product->update(['stock_qty' => $qty, 'is_available' => $available]);
        if ($available !== $product->getOriginal('is_available') || $qty === 0 || $was === 0) {
            Realtime::dispatch(new ProductAvailabilityChanged($product->outlet_id, $product->id, $available));
        }
        Audit::log('product.stock_set', $product, ['from' => $was, 'to' => $qty]);

        return response()->json(['stock_qty' => $qty, 'is_available' => $available]);
    }

    public function modifier(Modifier $modifier, Request $request): JsonResponse
    {
        $this->authorizeFor($request);
        abort_unless($modifier->modifierGroup()->where('outlet_id', $this->outlet->id())->exists(), 404);
        $modifier->update(['is_available' => $request->validate(['is_available' => 'required|boolean'])['is_available']]);
        Audit::log('modifier.availability', $modifier, ['name' => $modifier->name, 'is_available' => $modifier->is_available]);

        return response()->json(['is_available' => $modifier->is_available]);
    }

    public function variant(ProductVariant $variant, Request $request): JsonResponse
    {
        $this->authorizeFor($request);
        abort_unless($variant->product()->where('outlet_id', $this->outlet->id())->exists(), 404);
        $variant->update(['is_available' => $request->validate(['is_available' => 'required|boolean'])['is_available']]);
        Audit::log('variant.availability', $variant, ['name' => $variant->name, 'is_available' => $variant->is_available]);

        return response()->json(['is_available' => $variant->is_available]);
    }

    private function authorizeFor(Request $request): void
    {
        $user = $request->user();
        abort_unless($user !== null && ($user->can('manage-menu') || $user->can('mark-sold-out')), 403);
    }
}
