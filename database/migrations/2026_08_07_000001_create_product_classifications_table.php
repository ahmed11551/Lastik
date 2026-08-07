<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_classifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('product_id');
            $table->string('abc_class', 1)->default('C');   // A | B | C
            $table->string('xyz_class', 1)->default('Z');   // X | Y | Z
            $table->decimal('revenue_share', 8, 4)->default(0);        // доля в выручке, %
            $table->decimal('variation_coefficient', 8, 4)->default(0); // коэффициент вариации спроса, %
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'product_id']);
            $table->index(['tenant_id', 'abc_class', 'xyz_class']);
            $table->foreign('product_id')->references('id')->on('products_services')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_classifications');
    }
};
