<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products_services', function (Blueprint $table) {
            $table->string('category')->nullable()->after('type');
            $table->decimal('base_price', 12, 2)->nullable()->after('category');
            $table->json('radius_modifier')->nullable()->after('base_price');
        });
    }

    public function down(): void
    {
        Schema::table('products_services', function (Blueprint $table) {
            $table->dropColumn(['category', 'base_price', 'radius_modifier']);
        });
    }
};
