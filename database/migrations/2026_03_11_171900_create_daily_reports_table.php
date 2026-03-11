<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id();
            $table->date('report_date')->unique();
            $table->decimal('starting_balance', 12, 2);
            $table->decimal('ending_balance', 12, 2);
            $table->decimal('daily_pnl', 12, 2);
            $table->decimal('daily_pnl_pct', 8, 4);
            $table->integer('trades_opened');
            $table->integer('trades_closed');
            $table->integer('wins');
            $table->integer('losses');
            $table->json('strategy_breakdown');
            $table->json('pair_breakdown');
            $table->json('new_rules');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_reports');
    }
};
