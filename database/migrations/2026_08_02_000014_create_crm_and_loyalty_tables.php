<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            if (! Schema::hasColumn('customers', 'discount_card_number')) {
                $table->string('discount_card_number', 64)->nullable()->after('email');
            }
            if (! Schema::hasColumn('customers', 'bonus_balance')) {
                $table->decimal('bonus_balance', 14, 2)->default(0)->after('discount_card_number');
            }
            if (! Schema::hasColumn('customers', 'total_spent')) {
                $table->decimal('total_spent', 14, 2)->default(0)->after('bonus_balance');
            }
            if (! Schema::hasColumn('customers', 'tier')) {
                $table->string('tier', 16)->default('BRONZE')->after('total_spent');
            }
            if (! Schema::hasColumn('customers', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('tier')->constrained('users')->nullOnDelete();
            }
        });

        // Phone uniqueness is enforced at POS create (CustomerController validation).
        // Duplicate phones remain allowed for CRM merge workflows.

        if (! $this->hasIndex('customers', 'customers_tenant_card_unique')) {
            // Partial unique: ignore NULL cards (PostgreSQL).
            DB::statement('CREATE UNIQUE INDEX customers_tenant_card_unique ON customers (tenant_id, discount_card_number) WHERE discount_card_number IS NOT NULL');
        }

        if (! Schema::hasTable('loyalty_transactions')) {
            Schema::create('loyalty_transactions', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->foreignId('receipt_id')->nullable()->constrained('fiscal_receipts')->nullOnDelete();
                $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
                $table->string('type', 32);
                $table->decimal('amount', 14, 2);
                $table->decimal('balance_after', 14, 2);
                $table->string('meta', 500)->nullable();
                $table->timestampsTz();

                $table->index(['tenant_id', 'customer_id']);
                $table->index(['tenant_id', 'order_id']);
                $table->index(['tenant_id', 'type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');

        Schema::table('customers', function (Blueprint $table): void {
            if ($this->hasIndex('customers', 'customers_tenant_card_unique')) {
                DB::statement('DROP INDEX IF EXISTS customers_tenant_card_unique');
            }
            foreach (['created_by', 'tier', 'total_spent', 'bonus_balance', 'discount_card_number'] as $col) {
                if (Schema::hasColumn('customers', $col)) {
                    if ($col === 'created_by') {
                        $table->dropConstrainedForeignId('created_by');
                    } else {
                        $table->dropColumn($col);
                    }
                }
            }
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        $rows = DB::select(
            'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ? LIMIT 1',
            [$table, $index],
        );

        return $rows !== [];
    }
};
