<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_payments', function (Blueprint $t) {
            $t->id();
            $t->string('public_id', 40)->unique();
            $t->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_id')->constrained('orders');
            $t->foreignId('bill_id')->constrained('bills');
            $t->string('provider', 20);
            $t->string('provider_ref', 80)->nullable();
            $t->unsignedBigInteger('amount');
            $t->string('status', 12)->default('pending'); // pending | paid | expired | failed
            $t->text('qr_string')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $t->timestamp('applied_at')->nullable();
            $t->string('error', 250)->nullable();
            $t->timestamps();
            $t->index(['status', 'applied_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_payments');
    }
};
