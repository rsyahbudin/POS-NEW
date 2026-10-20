<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->string('number');
            $t->string('type'); // dine_in | takeaway | online
            $t->string('platform')->nullable();
            $t->foreignId('table_id')->nullable()->constrained('tables');
            $t->string('status')->default('open');
            $t->string('customer_name')->nullable();
            $t->bigInteger('subtotal')->default(0);
            $t->bigInteger('discount_total')->default(0);
            $t->bigInteger('charge_total')->default(0);
            $t->bigInteger('rounding')->default(0);
            $t->bigInteger('grand_total')->default(0);
            $t->bigInteger('paid_total')->default(0);
            $t->bigInteger('refunded_total')->default(0);
            $t->string('call_number')->nullable();
            $t->date('business_date');
            $t->foreignId('opened_by')->nullable()->constrained('users');
            $t->foreignId('shift_id')->nullable()->constrained('shifts');
            $t->string('cancel_reason')->nullable();
            $t->foreignId('cancelled_by')->nullable()->constrained('users');
            $t->timestamp('bill_requested_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->unique(['outlet_id', 'number']);
            $t->index(['outlet_id', 'business_date']);
            $t->index(['table_id', 'status']);
        });

        Schema::create('order_rounds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained('orders');
            $t->string('source'); // pos | qr
            $t->string('status')->default('confirmed'); // pending | confirmed | rejected
            $t->string('customer_name')->nullable();
            $t->foreignId('confirmed_by')->nullable()->constrained('users');
            $t->timestamp('confirmed_at')->nullable();
            $t->string('reject_reason')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamp('last_alerted_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'source']);
        });

        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_round_id')->constrained('order_rounds');
            $t->foreignId('order_id')->constrained('orders');
            $t->foreignId('product_id')->constrained('products');
            $t->foreignId('station_id')->nullable()->constrained('stations');
            $t->string('name_snapshot');
            $t->bigInteger('unit_price');
            $t->unsignedInteger('qty');
            $t->jsonb('modifiers_snapshot')->nullable();
            $t->string('note')->nullable();
            $t->bigInteger('line_total');
            $t->string('status')->default('new'); // pending|new|preparing|ready|voided|rejected
            $t->foreignId('voided_by')->nullable()->constrained('users');
            $t->foreignId('void_approved_by')->nullable()->constrained('users');
            $t->string('void_reason')->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->boolean('stock_reserved')->default(false);
            $t->string('comp_type')->nullable(); // complimentary | staff_meal
            $t->string('comp_reason')->nullable();
            $t->foreignId('comp_approved_by')->nullable()->constrained('users');
            $t->timestamp('preparing_at')->nullable();
            $t->timestamp('ready_at')->nullable();
            $t->timestamps();
            $t->index(['order_id']);
            $t->index(['station_id', 'status']);
        });

        Schema::create('order_charges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained('orders');
            $t->foreignId('tax_rule_id')->nullable()->constrained('tax_rules');
            $t->string('name_snapshot');
            $t->string('kind'); // tax | service
            $t->unsignedInteger('rate');
            $t->bigInteger('base');
            $t->bigInteger('amount');
            $t->boolean('is_inclusive')->default(false);
            $t->timestamps();
        });

        Schema::create('discounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained('orders');
            $t->foreignId('order_item_id')->nullable()->constrained('order_items');
            $t->string('type'); // percent | fixed | promo
            $t->unsignedBigInteger('value');
            $t->bigInteger('amount')->default(0);
            $t->string('reason');
            $t->foreignId('promotion_id')->nullable()->constrained('promotions');
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('removed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('bills', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('order_id')->constrained('orders');
            $t->string('label')->nullable();
            $t->string('split_mode')->nullable(); // item | equal | amount
            $t->boolean('is_unallocated')->default(false);
            $t->bigInteger('subtotal')->default(0);
            $t->bigInteger('discount_total')->default(0);
            $t->bigInteger('charge_total')->default(0);
            $t->bigInteger('rounding')->default(0);
            $t->bigInteger('grand_total')->default(0);
            $t->bigInteger('paid_total')->default(0);
            $t->string('status')->default('unpaid'); // unpaid | paid | void
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
        });

        Schema::create('bill_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bill_id')->constrained('bills');
            $t->foreignId('order_item_id')->constrained('order_items');
            $t->unsignedInteger('qty');
            $t->bigInteger('line_total');
            $t->timestamps();
        });

        Schema::create('bill_charges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bill_id')->constrained('bills');
            $t->foreignId('order_charge_id')->constrained('order_charges');
            $t->bigInteger('amount');
            $t->timestamps();
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained('orders');
            $t->foreignId('bill_id')->constrained('bills');
            $t->foreignId('payment_method_id')->constrained('payment_methods');
            $t->foreignId('account_id')->constrained('accounts');
            $t->bigInteger('amount');
            $t->bigInteger('tendered');
            $t->bigInteger('change')->default(0);
            $t->string('reference')->nullable();
            $t->string('idempotency_key')->unique();
            $t->foreignId('shift_id')->constrained('shifts');
            $t->foreignId('received_by')->constrained('users');
            $t->bigInteger('refunded_total')->default(0);
            $t->timestamps();
        });

        Schema::create('refunds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_id')->constrained('payments');
            $t->foreignId('order_id')->constrained('orders');
            $t->foreignId('shift_id')->nullable()->constrained('shifts');
            $t->bigInteger('amount');
            $t->string('reason');
            $t->string('idempotency_key')->unique();
            $t->foreignId('approved_by')->constrained('users');
            $t->timestamps();
        });

        foreach ([
            'orders' => ['subtotal', 'discount_total', 'charge_total', 'grand_total', 'paid_total', 'refunded_total'],
            'order_items' => ['unit_price', 'line_total'],
            'bills' => ['subtotal', 'discount_total', 'charge_total', 'grand_total', 'paid_total'],
            'payments' => ['amount', 'tendered', 'change'],
            'refunds' => ['amount'],
        ] as $table => $cols) {
            foreach ($cols as $col) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$col}_nonneg CHECK ({$col} >= 0)");
            }
        }
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive CHECK (amount > 0)');
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        foreach (['refunds', 'payments', 'bill_charges', 'bill_items', 'bills', 'discounts', 'order_charges', 'order_items', 'order_rounds', 'orders'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
