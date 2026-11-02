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
        Schema::table('attendances', function (Blueprint $t) {
            $t->string('in_flag', 12)->nullable();
            $t->string('out_flag', 12)->nullable();
            $t->string('in_reason', 200)->nullable();
            $t->string('out_reason', 200)->nullable();
            $t->string('review_status', 10)->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('review_note', 200)->nullable();
        });

        // "I forgot to clock in / out": the person asks, the manager on duty decides.
        Schema::create('attendance_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->date('work_date');
            $t->string('kind', 12);
            $t->time('clock_in')->nullable();
            $t->time('clock_out')->nullable();
            $t->string('reason', 200);
            $t->string('status', 10)->default('pending');
            $t->foreignId('attendance_id')->nullable()->constrained('attendances')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 200)->nullable();
            $t->timestamps();
            $t->index(['outlet_id', 'status']);
            $t->index(['user_id', 'work_date']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('approve-attendance', 'web');
        foreach (['owner', 'mod'] as $role) {
            Role::query()->where('name', $role)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }
    }

    public function down(): void
    {
        Permission::query()->where('name', 'approve-attendance')->delete();
        Schema::dropIfExists('attendance_requests');
        Schema::table('attendances', function (Blueprint $t) {
            $t->dropConstrainedForeignId('reviewed_by');
            $t->dropColumn(['in_flag', 'out_flag', 'in_reason', 'out_reason', 'review_status', 'reviewed_at', 'review_note']);
        });
    }
};
