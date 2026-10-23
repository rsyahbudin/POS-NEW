<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Pricing\Models\DiscountPreset;
use App\Domain\Settings\OutletContext;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DiscountPresetController extends Controller
{
    public function __construct(private OutletContext $outlet) {}

    public function save(Request $request, ?DiscountPreset $preset = null): RedirectResponse
    {
        $id = $this->outlet->id();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('discount_presets', 'name')->where('outlet_id', $id)->ignore($preset?->id)],
            'type' => 'required|in:percent,fixed',
            'value' => 'required|integer|min:1',
            'needs_pin' => 'boolean',
            'is_active' => 'boolean',
        ]);
        // The form sends a percentage as whole percent (20); it is stored in basis points.
        $value = $data['type'] === 'percent' ? (int) $data['value'] * 100 : (int) $data['value'];
        if ($data['type'] === 'percent' && $value > 10000) {
            return back()->withErrors(['value' => 'Diskon maksimal 100%.']);
        }
        $fields = ['name' => $data['name'], 'type' => $data['type'], 'value' => $value, 'needs_pin' => (bool) ($data['needs_pin'] ?? false), 'is_active' => (bool) ($data['is_active'] ?? true)];

        if ($preset) {
            abort_unless($preset->outlet_id === $id, 404);
            $preset->update($fields);
        } else {
            $preset = DiscountPreset::create($fields + ['outlet_id' => $id, 'created_by' => $this->actor($request)->id]);
        }
        Audit::log('settings.discount_preset', $preset, ['name' => $preset->name, 'type' => $preset->type, 'value' => $preset->value, 'needs_pin' => $preset->needs_pin, 'active' => $preset->is_active], $this->actor($request));

        return back()->with('success', 'Diskon disimpan.');
    }
}
