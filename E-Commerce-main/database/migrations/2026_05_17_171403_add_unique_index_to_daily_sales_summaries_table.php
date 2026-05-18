<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_sales_summaries', function (Blueprint $table) {
            $table->unique(['summary_date', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('daily_sales_summaries', function (Blueprint $table) {
            $table->dropUnique(['summary_date', 'product_id']);
        });
    }
};