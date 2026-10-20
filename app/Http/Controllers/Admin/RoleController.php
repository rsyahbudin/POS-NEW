<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Role editor: which permission each role holds is data, not hard-coded. */
class RoleController extends Controller
{
    public function index(): Response
    {
        $roles = Role::query()->with('permissions:id,name')->withCount('users')->orderBy('id')->get();

        return Inertia::render('admin/roles', [
            'groups' => collect(PermissionCatalog::groups())->map(fn (array $g, string $key) => [
                'key' => $key, 'label' => $g['label'],
                'items' => collect($g['items'])->map(fn (array $i, string $name) => ['name' => $name] + $i)->values()->all(),
            ])->values()->all(),
            'roles' => $roles->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'label' => PermissionCatalog::BUILT_IN[$r->name] ?? $r->name,
                'built_in' => array_key_exists($r->name, PermissionCatalog::BUILT_IN),
                'locked' => $r->name === 'owner',
                'pin_login' => (bool) $r->getAttribute('allow_pin_login'),
                'users_count' => $r->users_count,
                'permissions' => $r->permissions->pluck('name')->sort()->values()->all(),
            ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40', Rule::unique('roles', 'name')],
            'permissions' => 'array', 'permissions.*' => Rule::in(PermissionCatalog::all()),
        ]);
        $role = Role::create(['name' => Str::slug($data['name']), 'guard_name' => 'web']);
        $role->syncPermissions($data['permissions'] ?? []);
        Audit::log('role.created', null, ['role' => $role->name, 'permissions' => $data['permissions'] ?? []]);

        return back()->with('success', 'Peran dibuat.');
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        if ($role->name === 'owner') {
            return back()->with('error', 'Izin owner tidak bisa diubah.');
        }
        $data = $request->validate(['permissions' => 'array', 'permissions.*' => Rule::in(PermissionCatalog::all()), 'pin_login' => 'nullable|boolean']);
        $before = $role->permissions->pluck('name')->sort()->values()->all();
        $role->syncPermissions($data['permissions'] ?? []);
        if (array_key_exists('pin_login', $data) && (bool) $role->getAttribute('allow_pin_login') !== (bool) $data['pin_login']) {
            $role->forceFill(['allow_pin_login' => (bool) $data['pin_login']])->save();
            Audit::log('role.pin_login_changed', null, ['role' => $role->name, 'allowed' => (bool) $data['pin_login']]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $after = array_values($data['permissions'] ?? []);
        sort($after);
        Audit::log('role.permissions_changed', null, ['role' => $role->name, 'added' => array_values(array_diff($after, $before)), 'removed' => array_values(array_diff($before, $after))]);

        return back()->with('success', "Akses {$role->name} disimpan.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        if (array_key_exists($role->name, PermissionCatalog::BUILT_IN)) {
            return back()->with('error', 'Peran bawaan tidak bisa dihapus.');
        }
        if ($role->users()->exists()) {
            return back()->with('error', 'Peran masih dipakai user.');
        }
        $role->delete();
        Audit::log('role.deleted', null, ['role' => $role->name]);

        return back()->with('success', 'Peran dihapus.');
    }
}
