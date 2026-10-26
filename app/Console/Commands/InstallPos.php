<?php

namespace App\Console\Commands;

use App\Domain\Settings\Actions\SetupOutletAction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * First-time setup of a real installation: roles and permissions, the outlet with its chart of accounts, payment
 * methods and tax rules (all off), and ONE owner account with a password you choose. No sample menu, no sample
 * users and no default passwords (unlike `db:seed`, which is for local development only).
 */
class InstallPos extends Command
{
    protected $signature = 'pos:install {--outlet= : Nama kafe} {--address= : Alamat} {--name= : Nama owner} {--email= : Email owner} {--password= : Password owner (kosongkan agar ditanya)}';

    protected $description = 'Siapkan instalasi baru: peran, outlet, dan satu akun owner (tanpa data contoh)';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->error('Sudah ada pengguna di database ini, jadi instalasi awal tidak dijalankan lagi.');
            $this->line('Untuk menambah pengguna pakai halaman User di aplikasi. Untuk database percobaan yang berisi data contoh, hapus dulu datanya.');

            return self::FAILURE;
        }

        $opt = function (string $key): ?string {
            $value = $this->option($key);

            return is_string($value) && $value !== '' ? $value : null;
        };
        $guided = $opt('outlet') === null; // no options given: ask for everything
        $outlet = $opt('outlet') ?? text('Nama kafe', required: true, validate: ['string', 'max:80']);
        $address = $opt('address') ?? ($guided ? text('Alamat (boleh kosong)') : null);
        $name = $opt('name') ?? text('Nama owner', required: true, validate: ['string', 'max:80']);
        $email = $opt('email') ?? text('Email owner (dipakai untuk login)', required: true);
        $password = $opt('password') ?? password('Password owner (minimal 10 karakter)', required: true);

        $v = Validator::make(['email' => $email, 'password' => $password, 'outlet' => $outlet, 'name' => $name], [
            'email' => 'required|email', 'password' => 'required|string|min:10|max:100', 'outlet' => 'required|string|max:80', 'name' => 'required|string|max:80',
        ]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $this->callSilent('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
        $setup = app(SetupOutletAction::class)->handle($outlet, $address ?: null);

        $owner = User::query()->create([
            'name' => $name, 'email' => $email, 'password' => Hash::make($password), 'outlet_id' => $setup->id, 'email_verified_at' => now(),
        ]);
        $owner->syncRoles(['owner']);

        $this->info("Selesai. Outlet \"{$outlet}\" dan akun owner {$email} sudah dibuat.");
        $this->line('Langkah berikutnya: login, isi menu, meja, dan printer, lalu tambah staf di halaman User.');

        return self::SUCCESS;
    }
}
