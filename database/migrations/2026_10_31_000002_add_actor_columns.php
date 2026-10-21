<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Who did it, stored on the record itself (the activity log has the full story). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $t) {
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->foreignId('bill_requested_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('bills', function (Blueprint $t) {
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('order_items', function (Blueprint $t) {
            $t->foreignId('preparing_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('ready_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $t) => $t->dropConstrainedForeignId('ready_by'));
        Schema::table('order_items', fn (Blueprint $t) => $t->dropConstrainedForeignId('preparing_by'));
        Schema::table('bills', fn (Blueprint $t) => $t->dropConstrainedForeignId('created_by'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropConstrainedForeignId('bill_requested_by'));
        Schema::table('shifts', fn (Blueprint $t) => $t->dropConstrainedForeignId('closed_by'));
    }
};
