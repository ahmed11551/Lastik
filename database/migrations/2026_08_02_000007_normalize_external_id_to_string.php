<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Приведение external_id к строке (varchar) во всех таблицах обмена с 1С.
 * 1С шлёт произвольные строковые ИД (не только uuid), поэтому uuid-тип
 * заменяем на varchar(100). Идемпотентно: проверяем текущий тип колонки.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = ['products_services', 'warehouses', 'prices', 'categories', 'product_variants'];

        foreach ($tables as $table) {
            if (! Schema::hasColumn($table, 'external_id')) {
                continue;
            }

            $type = DB::selectOne(
                "SELECT data_type FROM information_schema.columns WHERE table_name = ? AND column_name = 'external_id'",
                [$table]
            );

            if ($type !== null && $type->data_type !== 'character varying') {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN external_id TYPE varchar(100)");
            }
        }
    }

    public function down(): void
    {
        // Обратное преобразование небезопасно (строковые ИД не влезут в uuid) — не делаем.
    }
};
