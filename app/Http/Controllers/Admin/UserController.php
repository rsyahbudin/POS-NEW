<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Settings\OutletContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/** Users are deactivated, never deleted (their transactions must stay attributable). */
class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/users', [
            'users' => User::query()->orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'is_active' => $u->is_active,
                'role' => $u->getRoleNames()->first(), 'has_pin' => $u->pin_hash !== null, 'has_2fa' => $u->two_factor_confirmed_at !== null,
            ]),
            'roles' => Role::query()->orderBy('id')->pluck('name'),
        ]);
    }

    public function store(Request $request, OutletContext $outlet): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:80', 'email' => 'required|email|unique:users,email',
            'password' => ['required', 'string', 'min:8'], 'role' => ['required', Rule::exists('roles', 'name')],
            'pin' => 'nullable|digits:6',
        ]);
        $this->guardRoleAssignment($request, $data['role']);
        $this->guardPinUnique($data['pin'] ?? null);

        $user = User::create([
            'name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'outlet_id' => $outlet->id(),
            'pin_hash' => isset($data['pin']) ? Hash::make($data['pin']) : null, 'email_verified_at' => now(),
        ]);
        $user->syncRoles([$data['role']]);
        Audit::log('user.created', $user, ['role' => $data['role']]);

        return back()->with('success', 'User dibuat.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:80', 'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::exists('roles', 'name')],
        ]);
        $this->guardRoleAssignment($request, $data['role']);

        $user->update(['name' => $data['name'], 'email' => $data['email']]);
        if ($user->getRoleNames()->first() !== $data['role']) {
            Audit::log('user.role_changed', $user, ['from' => $user->getRoleNames()->first(), 'to' => $data['role']]);
            $user->syncRoles([$data['role']]);
        }

        return back()->with('success', 'User diperbarui.');
    }

    public function toggle(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $this->actor($request)->id) {
            return back()->with('error', 'Tidak bisa menonaktifkan akun sendiri.');
        }
        $user->update(['is_active' => ! $user->is_active]);
        Audit::log($user->is_active ? 'user.activated' : 'user.deactivated', $user);

        return back()->with('success', $user->is_active ? 'User diaktifkan.' : 'User dinonaktifkan.');
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['password' => 'required|string|min:8']);
        $user->update(['password' => $data['password']]);
        Audit::log('user.password_reset', $user);

        return back()->with('success', 'Password direset.');
    }

    public function setPin(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['pin' => 'required|digits:6']);
        $this->guardPinUnique($data['pin'], $user->id);
        $user->forceFill(['pin_hash' => Hash::make($data['pin']), 'pin_attempts' => 0, 'pin_locked_until' => null])->save();
        Audit::log('user.pin_set', $user);

        return back()->with('success', 'PIN disimpan.');
    }

    private function guardRoleAssignment(Request $request, string $role): void
    {
        abort_if($role === 'owner' && ! $this->actor($request)->hasRole('owner'), 403);
    }

    /** A PIN identifies the approving manager, so two staff must not share one. */
    private function guardPinUnique(?string $pin, ?int $exceptId = null): void
    {
        if ($pin === null) {
            return;
        }
        $clash = User::query()->whereNotNull('pin_hash')->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->get()
            ->contains(fn (User $u) => Hash::check($pin, (string) $u->pin_hash));
        abort_if($clash, 422, 'PIN sudah dipakai staf lain. Pilih PIN lain.');
    }
}
