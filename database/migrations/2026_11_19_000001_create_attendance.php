<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // Face data is a list of 128 numbers per sample, never a photo.
        Schema::create('face_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->json('descriptor');
            $t->timestamp('consented_at');
            $t->foreignId('enrolled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['outlet_id', 'user_id']);
        });

        Schema::create('attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->date('work_date');
            $t->timestamp('clock_in_at');
            $t->timestamp('clock_out_at')->nullable();
            $t->string('method_in', 10)->default('face');
            $t->string('method_out', 10)->nullable();
            $t->float('distance')->nullable();
            $t->string('note', 200)->nullable();
            $t->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['outlet_id', 'work_date']);
            $t->index(['user_id', 'clock_out_at']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $manage = Permission::findOrCreate('manage-attendance', 'web');
        $kiosk = Permission::findOrCreate('use-attendance-kiosk', 'web');
        foreach (['owner', 'manager'] as $role) {
            Role::query()->where('name', $role)->where('guard_name', 'web')->first()?->givePermissionTo([$manage, $kiosk]);
        }
        Role::query()->where('name', 'cashier')->where('guard_name', 'web')->first()?->givePermissionTo($kiosk);
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', ['manage-attendance', 'use-attendance-kiosk'])->delete();
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('face_templates');
    }
};
