<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // In-app notifications. `permissions` (any-of) decides which roles see one; `user_id` targets one person.
        Schema::create('app_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 40);
            $t->string('title', 140);
            $t->text('body')->nullable();
            $t->string('url', 200)->nullable();
            $t->string('level', 8)->default('info'); // info | warning | danger
            $t->jsonb('permissions')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('dedupe_key', 80)->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['outlet_id', 'resolved_at', 'id']);
            $t->index('dedupe_key');
        });

        Schema::create('notification_reads', function (Blueprint $t) {
            $t->foreignId('notification_id')->constrained('app_notifications')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('read_at')->useCurrent();
            $t->primary(['notification_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_reads');
        Schema::dropIfExists('app_notifications');
    }
};
