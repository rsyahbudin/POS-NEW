<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Notification\Jobs\DeliverMessageJob;
use App\Domain\Notification\ReportDelivery;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Pricing\Models\Promotion;
use App\Domain\Pricing\Models\TaxRule;
use App\Domain\Settings\Models\Outlet;
use App\Domain\Settings\OutletContext;
use App\Domain\Settings\SettingsCatalog;
use App\Domain\Settings\SettingsRepository;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\ImageOptimizer;
use App\Support\MediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SettingsController extends Controller
{
    public function __construct(private OutletContext $outlet, private SettingsRepository $settings) {}

    public function show(): Response
    {
        $id = $this->outlet->id();
        $values = $this->settings->withDefaults();
        // Secrets never leave the server in plain text.
        foreach (SettingsCatalog::fields() as $key => $f) {
            if ($f['type'] === 'secret') {
                $values[$key] = $values[$key] ? '••••••••' : null;
            }
        }

        return Inertia::render('admin/settings', [
            'outlet' => $this->outletProps(Outlet::findOrFail($id)),
            'settings' => $values,
            'catalog' => SettingsCatalog::tabs(),
        ]);
    }

    /** @return array<string, mixed> */
    private function outletProps(Outlet $o): array
    {
        return $o->only(['name', 'address', 'npwp', 'tagline', 'opening_hours', 'phone', 'instagram', 'wifi_name', 'wifi_password', 'about', 'whatsapp', 'tiktok', 'facebook', 'website', 'maps_url', 'google_review_url', 'latitude', 'longitude'])
            + ['logo' => MediaUrl::public($o->logo_path), 'banner' => MediaUrl::public($o->banner_path)];
    }

    public function updateOutlet(Request $request, ImageOptimizer $images): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:80', 'address' => 'nullable|string|max:200', 'npwp' => 'nullable|string|max:40', 'logo' => 'nullable|image|max:4096',
            'banner' => 'nullable|image|max:8192', 'remove_banner' => 'nullable|boolean', 'tagline' => 'nullable|string|max:120', 'opening_hours' => 'nullable|string|max:160',
            'phone' => 'nullable|string|max:40', 'instagram' => 'nullable|string|max:60', 'wifi_name' => 'nullable|string|max:60', 'wifi_password' => 'nullable|string|max:80',
            'about' => 'nullable|string|max:400', 'whatsapp' => 'nullable|string|max:40', 'tiktok' => 'nullable|string|max:60', 'facebook' => 'nullable|string|max:120',
            'website' => 'nullable|string|max:160', 'maps_url' => 'nullable|url:http,https|max:300', 'google_review_url' => 'nullable|url:http,https|max:300',
            'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180',
        ]);
        if (! empty($data['website']) && ! preg_match('#^https?://#i', $data['website'])) {
            $data['website'] = 'https://'.$data['website'];
        }
        $outlet = Outlet::findOrFail($this->outlet->id());
        $fields = Arr::except($data, ['logo', 'banner', 'remove_banner']);
        if ($request->hasFile('logo')) {
            $fields['logo_path'] = $images->store($request->file('logo'), 'logo');
        }
        if ($request->hasFile('banner')) {
            $fields['banner_path'] = $images->store($request->file('banner'), 'banner', 1400, 220 * 1024);
        } elseif (! empty($data['remove_banner'])) {
            $fields['banner_path'] = null;
        }
        $outlet->update($fields);
        Audit::log('settings.outlet', $outlet, $fields);

        return back()->with('success', 'Profil outlet disimpan.');
    }

    /**
     * Setting keys contain dots ("qr.auto_confirm"), which Laravel's validator would read as
     * nested paths. They are therefore sent inside `values` and validated one by one.
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $input = $request->input('values');
        if (! is_array($input)) {
            throw ValidationException::withMessages(['values' => 'Data pengaturan tidak valid.']);
        }

        $rules = SettingsCatalog::rules();
        $fields = SettingsCatalog::fields();
        $clean = [];
        $errors = [];
        foreach ($input as $key => $value) {
            if (! is_string($key) || ! isset($rules[$key])) {
                continue; // unknown keys are ignored, never stored
            }
            $validator = Validator::make(['value' => $value], ['value' => $rules[$key]], [], ['value' => $fields[$key]['label']]);
            if ($validator->fails()) {
                $errors['values'] = $validator->errors()->first();

                break;
            }
            $clean[$key] = $value;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($clean as $key => $value) {
            if ((SettingsCatalog::fields()[$key]['type'] ?? null) === 'secret' && $value === '••••••••') {
                continue; // masked placeholder: keep the stored token
            }
            $old = $this->settings->get($key);
            $new = SettingsCatalog::cast($key, $value);
            if ($old !== $new) {
                $this->settings->set($key, $new);
                $secret = (SettingsCatalog::fields()[$key]['type'] ?? null) === 'secret';
                Audit::log('settings.changed', null, ['key' => $key, 'from' => $secret ? '***' : $old, 'to' => $secret ? '***' : $new]);
            }
        }

        return back()->with('success', 'Pengaturan disimpan.');
    }

    public function savePaymentMethod(Request $request, ?PaymentMethod $method = null): RedirectResponse
    {
        $id = $this->outlet->id();
        $data = $request->validate([
            'name' => 'required|string|max:60', 'type' => 'required|in:cash,qris,transfer',
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('outlet_id', $id)->where('type', 'asset')],
            'is_active' => 'boolean',
        ]);
        $method ? $method->update($data) : PaymentMethod::create($data + ['outlet_id' => $id]);
        Audit::log('settings.payment_method', $method, $data);

        return back()->with('success', 'Metode pembayaran disimpan.');
    }

    public function saveTaxRule(Request $request, ?TaxRule $rule = null): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:60', 'kind' => 'required|in:tax,service', 'rate_bp' => 'required|integer|min:0|max:10000',
            'sequence' => 'required|integer|min:0|max:99', 'base' => 'required|in:after_discount,cumulative', 'is_inclusive' => 'boolean', 'is_active' => 'boolean',
        ]);
        $old = $rule?->only(array_keys($data));
        $rule ? $rule->update($data) : $rule = TaxRule::create($data + ['outlet_id' => $this->outlet->id()]);
        Audit::log('tax.changed', $rule, ['from' => $old, 'to' => $data]);

        return back()->with('success', 'Pajak/biaya disimpan. Berlaku untuk order baru.');
    }

    public function savePromotion(Request $request, ?Promotion $promotion = null): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:60', 'type' => 'required|in:percent,fixed', 'value' => 'required|integer|min:1',
            'starts_at' => 'required|date_format:H:i', 'ends_at' => 'required|date_format:H:i', 'days' => 'nullable|array', 'days.*' => 'integer|min:1|max:7', 'starts_on' => 'nullable|date', 'ends_on' => 'nullable|date|after_or_equal:starts_on', 'is_active' => 'boolean',
            'scope' => 'required|in:order,category,product', 'combinable' => 'boolean',
            'category_ids' => 'nullable|array', 'category_ids.*' => 'integer', 'product_ids' => 'nullable|array', 'product_ids.*' => 'integer',
        ]);
        $outletId = $this->outlet->id();
        $data['category_ids'] = $data['scope'] === 'category' ? Category::query()->where('outlet_id', $outletId)->whereIn('id', $data['category_ids'] ?? [])->pluck('id')->all() : null;
        $data['product_ids'] = $data['scope'] === 'product' ? Product::query()->where('outlet_id', $outletId)->whereIn('id', $data['product_ids'] ?? [])->pluck('id')->all() : null;
        if ($data['scope'] !== 'order' && ($data['scope'] === 'category' ? $data['category_ids'] : $data['product_ids']) === []) {
            return back()->withErrors(['scope' => 'Pilih minimal satu kategori atau menu untuk promo ini.']);
        }
        $promotion ? $promotion->update($data) : Promotion::create($data + ['outlet_id' => $outletId]);
        Audit::log('settings.promotion', $promotion, $data);

        return back()->with('success', 'Promo disimpan.');
    }

    public function deletePromotion(Promotion $promotion): RedirectResponse
    {
        $promotion->update(['is_active' => false]);

        return back()->with('success', 'Promo dinonaktifkan.');
    }

    /** Sends a test message right away so wrong tokens or numbers show up now, not at closing time. */
    public function testNotification(Request $request, ReportDelivery $delivery): JsonResponse
    {
        $data = $request->validate(['channel' => ['required', Rule::in(array_keys(ReportDelivery::CHANNELS))]]);
        if (! in_array($data['channel'], $delivery->configured(), true)) {
            return response()->json(['message' => ReportDelivery::CHANNELS[$data['channel']].' belum diisi atau belum disimpan.'], 422);
        }

        try {
            DeliverMessageJob::dispatchSync($data['channel'], 'Tes notifikasi', 'Ini pesan uji dari POS. Kalau Anda menerimanya, saluran sudah benar.');
        } catch (Throwable $e) {
            return response()->json(['message' => 'Gagal mengirim: '.mb_substr($e->getMessage(), 0, 160)], 422);
        }

        return response()->json(['message' => 'Terkirim ke '.ReportDelivery::CHANNELS[$data['channel']].'. Periksa pesan masuk.']);
    }
}
