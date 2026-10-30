<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** What a manager on duty may do: run any station, plan the schedule, approve longshift. Editable in Peran & Akses. */
    private const MOD_PERMISSIONS = [
        'handle-calls', 'view-today-summary', 'create-order', 'receive-payment', 'discount-within-limit', 'discount-custom', 'void', 'cancel-order',
        'open-close-shift', 'manage-shifts-any', 'reopen-shift', 'update-kitchen-status', 'input-expense', 'use-print-station',
        'view-stock', 'count-stock', 'view-assets', 'view-schedule', 'manage-schedule', 'approve-longshift', 'use-attendance-kiosk',
    ];

    public function up(): void
    {
        Schema::table('work_schedules', function (Blueprint $t) {
            $t->dropUnique(['user_id', 'work_date', 'work_shift_type_id']);
            $t->string('position', 10)->default('none');
            $t->unique(['user_id', 'work_date', 'work_shift_type_id', 'position']);
        });

        // Hours worked beyond the standard shift, to be approved by the manager on duty.
        Schema::create('longshift_approvals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->date('work_date');
            $t->string('source', 10);
            $t->unsignedSmallInteger('hours');
            $t->unsignedSmallInteger('approved_hours')->nullable();
            $t->string('status', 10)->default('pending');
            $t->foreignId('attendance_id')->nullable()->constrained('attendances')->nullOnDelete();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('note', 200)->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'work_date', 'source']);
            $t->index(['outlet_id', 'status']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $approve = Permission::findOrCreate('approve-longshift', 'web');
        foreach (self::MOD_PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }
        Role::query()->where('name', 'owner')->where('guard_name', 'web')->first()?->givePermissionTo($approve);
        $mod = Role::findOrCreate('mod', 'web');
        if ($mod->wasRecentlyCreated) {
            $mod->syncPermissions(self::MOD_PERMISSIONS);
            $mod->forceFill(['allow_pin_login' => true])->save();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('longshift_approvals');
        Schema::table('work_schedules', function (Blueprint $t) {
            $t->dropUnique(['user_id', 'work_date', 'work_shift_type_id', 'position']);
            $t->dropColumn('position');
            $t->unique(['user_id', 'work_date', 'work_shift_type_id']);
        });
        Permission::query()->where('name', 'approve-longshift')->delete();
        Role::query()->where('name', 'mod')->delete();
    }
};
