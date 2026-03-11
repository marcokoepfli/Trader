<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_scores', function (Blueprint $table) {
            $table->id();
            $table->string('strategy')->unique();
            $table->decimal('score', 4, 2)->default(0.50);
            $table->decimal('win_rate', 5, 2)->default(0);
            $table->decimal('avg_pnl', 12, 2)->default(0);
            $table->decimal('profit_factor', 6, 2)->default(0);
            $table->integer('total_trades')->default(0);
            $table->integer('wins')->default(0);
            $table->integer('losses')->default(0);
            $table->integer('consecutive_losses')->default(0);
            $table->boolean('on_cooldown')->default(false);
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_scores');
    }
};
