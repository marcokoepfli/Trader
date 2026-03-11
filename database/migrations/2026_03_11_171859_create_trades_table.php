<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->string('oanda_trade_id')->nullable();
            $table->string('pair', 10);
            $table->enum('direction', ['BUY', 'SELL']);
            $table->string('strategy');
            $table->decimal('entry_price', 12, 6);
            $table->decimal('exit_price', 12, 6)->nullable();
            $table->decimal('stop_loss', 12, 6);
            $table->decimal('take_profit', 12, 6);
            $table->integer('position_size');
            $table->decimal('pnl', 12, 2)->nullable();
            $table->decimal('pnl_pct', 8, 4)->nullable();
            $table->enum('result', ['OPEN', 'WIN', 'LOSS'])->default('OPEN');
            $table->decimal('confluence_score', 4, 2);
            $table->string('session', 20);
            $table->string('market_condition', 20);
            $table->json('indicators_at_entry');
            $table->json('indicators_at_exit')->nullable();
            $table->decimal('max_favorable', 12, 6)->nullable();
            $table->decimal('max_adverse', 12, 6)->nullable();
            $table->boolean('hit_stop_loss')->default(false);
            $table->boolean('hit_take_profit')->default(false);
            $table->decimal('slippage', 8, 6)->default(0);
            $table->text('reasoning');
            $table->text('exit_notes')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['pair', 'strategy', 'result']);
            $table->index('opened_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
