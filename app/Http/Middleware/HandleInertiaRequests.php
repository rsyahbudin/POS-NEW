<?php

namespace App\Http\Middleware;

use App\Domain\Printing\PrintDispatcher;
use App\Domain\Settings\SettingsRepository;
use App\Support\RealtimeConfig;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user() ? [
                    ...$request->user()->toArray(),
                    'roles' => $request->user()->getRoleNames(),
                    'permissions' => $request->user()->getAllPermissions()->pluck('name'),
                ] : null,
            ],
            'features' => [
                'kds' => (bool) app(SettingsRepository::class)->get('kitchen.enabled', false),
                'inventory' => (bool) app(SettingsRepository::class)->get('inventory.enabled', false),
            ],
            'print_routing' => fn () => $request->user()?->outlet_id ? PrintDispatcher::routing((int) $request->user()->outlet_id) : ['receipt' => false, 'bill' => false, 'ticket' => false],
            'realtime' => fn () => $request->user() ? RealtimeConfig::forRequest($request) : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
