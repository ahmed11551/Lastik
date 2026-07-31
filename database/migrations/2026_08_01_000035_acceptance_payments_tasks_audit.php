<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_corrections', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_corrections', 'old_amount')) {
                $table->decimal('old_amount', 12, 2)->nullable()->after('payment_id');
            }
            if (! Schema::hasColumn('payment_corrections', 'new_amount')) {
                $table->decimal('new_amount', 12, 2)->nullable()->after('old_amount');
            }
            if (! Schema::hasColumn('payment_corrections', 'created_by')) {
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('cash_movements', 'reason')) {
                $table->text('reason')->nullable()->after('amount');
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'type')) {
                $table->string('type', 40)->nullable()->after('method');
            }
            if (! Schema::hasColumn('payments', 'created_by')) {
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('earnings', function (Blueprint $table) {
            if (! Schema::hasColumn('earnings', 'order_item_id')) {
                $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            }
            if (! Schema::hasColumn('earnings', 'percent')) {
                $table->decimal('percent', 6, 3)->nullable()->after('amount');
            }
        });

        if (! Schema::hasTable('money_recipients')) {
            Schema::create('money_recipients', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('type', 40); // cash_desk|card_fio|ip_account|ooo_account|other
                $table->string('name');
                $table->string('details')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestampsTz();
                $table->index('tenant_id');
            });
        }

        if (! Schema::hasTable('tasks')) {
            Schema::create('tasks', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
                $table->string('title');
                $table->text('body')->nullable();
                $table->string('status', 40)->default('open'); // open|done|cancelled
                $table->text('cancel_reason')->nullable();
                $table->timestampTz('completed_at')->nullable();
                $table->timestampsTz();
                $table->index('tenant_id');
            });
        }

        if (! Schema::hasTable('customer_merges')) {
            Schema::create('customer_merges', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('primary_customer_id')->constrained('customers')->cascadeOnDelete();
                $table->foreignId('merged_customer_id')->constrained('customers')->cascadeOnDelete();
                $table->foreignId('merged_by')->constrained('users')->cascadeOnDelete();
                $table->jsonb('transferred')->nullable();
                $table->text('reason')->nullable();
                $table->timestampsTz();
                $table->index('tenant_id');
            });
        }

        // Append-only audit_logs at DB level (PostgreSQL)
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_audit_log_mutation()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_logs is append-only';
                END;
                $$ LANGUAGE plpgsql;

                DROP TRIGGER IF EXISTS audit_logs_no_update ON audit_logs;
                DROP TRIGGER IF EXISTS audit_logs_no_delete ON audit_logs;

                CREATE TRIGGER audit_logs_no_update
                    BEFORE UPDATE ON audit_logs
                    FOR EACH ROW EXECUTE PROCEDURE prevent_audit_log_mutation();

                CREATE TRIGGER audit_logs_no_delete
                    BEFORE DELETE ON audit_logs
                    FOR EACH ROW EXECUTE PROCEDURE prevent_audit_log_mutation();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update ON audit_logs');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete ON audit_logs');
        }

        Schema::dropIfExists('customer_merges');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('money_recipients');
    }
};
