<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ramadan flow: one buka-puasa time a day with a quota of people, bookings wait for a confirmed payment (deposit
     * or paid in full), one package per person, and the guest may change or cancel until a set time.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('reservation_package')->default(false)->after('reservation_menu');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->string('payment_type', 4)->default('dp')->after('items_total');   // dp | full
            $table->bigInteger('amount_due')->default(0)->after('payment_type');       // what the guest was asked to pay
            $table->bigInteger('paid_amount')->default(0)->after('amount_due');        // what staff confirmed as received
            $table->timestamp('confirmed_at')->nullable()->after('paid_amount');
            $table->foreignId('confirmed_by')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();
            $table->boolean('package_short')->default(false)->after('confirmed_by');   // fewer packages than people
            $table->boolean('package_waived')->default(false)->after('package_short'); // staff accepted that
            $table->boolean('full_menu')->default(false)->after('package_waived');     // staff let this party order the normal menu
            $table->timestamp('changed_at')->nullable()->after('full_menu');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['payment_type', 'amount_due', 'paid_amount', 'confirmed_at', 'package_short', 'package_waived', 'full_menu', 'changed_at']);
        });
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('reservation_package'));
    }
};
