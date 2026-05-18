<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('daily_sales_summaries', function (Blueprint $table) {
            $table->decimal('total_cost', 10, 2)->default(0)->after('total_sales');
            $table->decimal('total_profit', 10, 2)->default(0)->after('total_cost');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_sales_summaries', function (Blueprint $table) {
            $table->dropColumn(['total_cost', 'total_profit']);
        });
    }
};