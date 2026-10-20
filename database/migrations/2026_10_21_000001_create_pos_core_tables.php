<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlets', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('address')->nullable();
            $t->string('timezone')->default('Asia/Jakarta');
            $t->string('npwp')->nullable();
            $t->string('logo_path')->nullable();
            $t->timestamps();
        });

        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('outlet_id')->nullable()->constrained('outlets');
            $t->string('pin_hash')->nullable();
            $t->unsignedSmallInteger('pin_attempts')->default(0);
            $t->timestamp('pin_locked_until')->nullable();
            $t->boolean('is_active')->default(true);
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->jsonb('value')->nullable();
            $t->timestamps();
        });

        Schema::create('stations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->string('name');
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->string('name');
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->foreignId('category_id')->constrained('categories');
            $t->foreignId('station_id')->nullable()->constrained('stations');
            $t->string('name');
            $t->string('sku')->nullable();
            $t->text('description')->nullable();
            $t->bigInteger('base_price');
            $t->string('image_path')->nullable();
            $t->boolean('is_available')->default(true);
            $t->integer('stock_qty')->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->softDeletes();
            $t->timestamps();
            $t->unique(['outlet_id', 'sku']);
        });

        Schema::create('product_variants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained('products');
            $t->string('name');
            $t->bigInteger('price');
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('modifier_groups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->string('name');
            $t->unsignedSmallInteger('min_select')->default(0);
            $t->unsignedSmallInteger('max_select')->default(1);
            $t->boolean('is_required')->default(false);
            $t->timestamps();
        });

        Schema::create('modifiers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('modifier_group_id')->constrained('modifier_groups');
            $t->string('name');
            $t->bigInteger('price_delta')->default(0);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('modifier_group_product', function (Blueprint $t) {
            $t->foreignId('product_id')->constrained('products');
            $t->foreignId('modifier_group_id')->constrained('modifier_groups');
            $t->primary(['product_id', 'modifier_group_id']);
        });

        Schema::create('areas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->string('name');
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('tables', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->foreignId('area_id')->constrained('areas');
            $t->string('name');
            $t->unsignedSmallInteger('capacity')->default(2);
            $t->string('qr_token', 32)->unique();
            $t->boolean('qr_enabled')->default(true);
            $t->string('status')->default('empty');
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('tax_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->string('name');
            $t->string('kind')->default('tax'); // tax | service
            $t->unsignedInteger('rate_bp');
            $t->unsignedSmallInteger('sequence')->default(0);
            $t->string('base')->default('after_discount'); // after_discount | cumulative
            $t->boolean('is_inclusive')->default(false);
            $t->boolean('is_active')->default(false);
            $t->timestamps();
        });

        Schema::create('promotions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->string('name');
            $t->string('type'); // percent | fixed
            $t->unsignedBigInteger('value');
            $t->time('starts_at');
            $t->time('ends_at');
            $t->jsonb('days')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // Accounting
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->string('code');
            $t->string('name');
            $t->string('type'); // asset|liability|equity|revenue|expense
            $t->string('system_key')->nullable();
            $t->boolean('is_system')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['outlet_id', 'code']);
        });

        Schema::create('periods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->unsignedSmallInteger('month');
            $t->unsignedSmallInteger('year');
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['outlet_id', 'year', 'month']);
        });

        Schema::create('journal_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->date('date');
            $t->foreignId('period_id')->constrained('periods');
            $t->string('source_type')->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('memo')->nullable();
            $t->boolean('is_manual')->default(false);
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamp('posted_at');
            $t->foreignId('reversed_by')->nullable()->constrained('journal_entries');
            $t->foreignId('reverses')->nullable()->constrained('journal_entries');
            $t->timestamps();
            $t->index(['source_type', 'source_id']);
            $t->index(['outlet_id', 'date']);
        });

        Schema::create('journal_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('journal_entry_id')->constrained('journal_entries');
            $t->foreignId('account_id')->constrained('accounts');
            $t->bigInteger('debit')->default(0);
            $t->bigInteger('credit')->default(0);
            $t->timestamps();
            $t->index('account_id');
        });

        Schema::create('expense_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->string('name');
            $t->foreignId('account_id')->constrained('accounts');
            $t->timestamps();
        });

        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->date('date');
            $t->foreignId('category_id')->constrained('expense_categories');
            $t->bigInteger('amount');
            $t->foreignId('source_account_id')->constrained('accounts');
            $t->string('note')->nullable();
            $t->string('receipt_path')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->string('name');
            $t->string('type'); // cash | qris | transfer
            $t->foreignId('account_id')->constrained('accounts');
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        // Shifts
        Schema::create('shifts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->foreignId('user_id')->constrained('users');
            $t->string('device_label')->nullable();
            $t->date('business_date');
            $t->bigInteger('opening_cash');
            $t->bigInteger('expected_cash')->nullable();
            $t->bigInteger('counted_cash')->nullable();
            $t->jsonb('denominations')->nullable();
            $t->bigInteger('cash_left')->nullable();
            $t->bigInteger('cash_deposited')->nullable();
            $t->foreignId('deposit_account_id')->nullable()->constrained('accounts');
            $t->bigInteger('difference')->nullable();
            $t->string('difference_reason')->nullable();
            $t->timestamp('opened_at');
            $t->timestamp('closed_at')->nullable();
            $t->string('close_key')->nullable()->unique();
            $t->timestamps();
        });

        Schema::create('cash_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shift_id')->constrained('shifts');
            $t->string('direction'); // in | out
            $t->bigInteger('amount');
            $t->string('reason');
            $t->foreignId('account_id')->constrained('accounts');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });

        Schema::create('business_days', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->date('date');
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users');
            $t->jsonb('summary')->nullable();
            $t->timestamps();
            $t->unique(['outlet_id', 'date']);
        });

        Schema::create('daily_counters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->date('date');
            $t->string('key');
            $t->unsignedInteger('value')->default(0);
            $t->unique(['outlet_id', 'date', 'key']);
        });

        $this->moneyChecks();
    }

    private function moneyChecks(): void
    {
        $checks = [
            'products' => ['base_price'],
            'product_variants' => ['price'],
            'expenses' => ['amount'],
            'cash_movements' => ['amount'],
            'shifts' => ['opening_cash'],
        ];
        foreach ($checks as $table => $cols) {
            foreach ($cols as $col) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$col}_nonneg CHECK ({$col} >= 0)");
            }
        }
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_one_side CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))');
        DB::statement('CREATE UNIQUE INDEX shifts_one_open_per_user ON shifts (user_id) WHERE closed_at IS NULL');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION check_journal_balance() RETURNS trigger AS $$
DECLARE d bigint; c bigint;
BEGIN
  SELECT COALESCE(SUM(debit),0), COALESCE(SUM(credit),0) INTO d, c
  FROM journal_lines WHERE journal_entry_id = NEW.journal_entry_id;
  IF d <> c THEN
    RAISE EXCEPTION 'Journal entry % unbalanced: debit % <> credit %', NEW.journal_entry_id, d, c;
  END IF;
  RETURN NULL;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::unprepared('CREATE CONSTRAINT TRIGGER journal_balance_check AFTER INSERT OR UPDATE ON journal_lines DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_journal_balance()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS journal_balance_check ON journal_lines');
        DB::unprepared('DROP FUNCTION IF EXISTS check_journal_balance()');
        foreach (['daily_counters', 'business_days', 'cash_movements', 'shifts', 'payment_methods', 'expenses', 'expense_categories', 'journal_lines', 'journal_entries', 'periods', 'accounts', 'promotions', 'tax_rules', 'tables', 'areas', 'modifier_group_product', 'modifiers', 'modifier_groups', 'product_variants', 'products', 'categories', 'stations', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('outlet_id');
            $t->dropColumn(['pin_hash', 'pin_attempts', 'pin_locked_until', 'is_active']);
        });
        Schema::dropIfExists('outlets');
    }
};
