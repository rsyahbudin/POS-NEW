<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Models\Account;
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
        $values['telegram.bot_token'] = $values['telegram.bot_token'] ? '••••••••' : null;

        return Inertia::render('admin/settings', [
            'outlet' => Outlet::find($id)?->only(['name', 'address', 'npwp']) + ['logo' => Outlet::find($id)?->logo_path ? MediaUrl::public(Outlet::find($id)->logo_path) : null],
            'settings' => $values,
            'catalog' => SettingsCatalog::tabs(),
            'paymentMethods' => PaymentMethod::query()->where('outlet_id', $id)->orderBy('sort')->get(['id', 'name', 'type', 'account_id', 'is_active']),
            'cashAccounts' => Account::query()->where('outlet_id', $id)->where('type', 'asset')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'taxRules' => TaxRule::query()->where('outlet_id', $id)->orderBy('sequence')->get(['id', 'name', 'kind', 'rate_bp', 'sequence', 'base', 'is_inclusive', 'is_active']),
            'promotions' => Promotion::query()->where('outlet_id', $id)->get(),
        ]);
    }

    public function updateOutlet(Request $request, ImageOptimizer $images): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:80', 'address' => 'nullable|string|max:200', 'npwp' => 'nullable|string|max:40', 'logo' => 'nullable|image|max:4096']);
        $outlet = Outlet::findOrFail($this->outlet->id());
        $fields = Arr::except($data, ['logo']);
        if ($request->hasFile('logo')) {
            $fields['logo_path'] = $images->store($request->file('logo'), 'logo');
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
            if ($key === 'telegram.bot_token' && $value === '••••••••') {
                continue; // masked placeholder: keep the stored token
            }
            $old = $this->settings->get($key);
            $new = SettingsCatalog::cast($key, $value);
            if ($old !== $new) {
                $this->settings->set($key, $new);
                $secret = str_contains($key, 'token');
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
            'starts_at' => 'required|date_format:H:i', 'ends_at' => 'required|date_format:H:i', 'days' => 'nullable|array', 'days.*' => 'integer|min:1|max:7', 'is_active' => 'boolean',
        ]);
        $promotion ? $promotion->update($data) : Promotion::create($data + ['outlet_id' => $this->outlet->id()]);
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
