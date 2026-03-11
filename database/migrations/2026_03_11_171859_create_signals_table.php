<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signals', function (Blueprint $table) {
            $table->id();
            $table->string('pair', 10);
            $table->string('strategy');
            $table->enum('direction', ['BUY', 'SELL']);
            $table->decimal('confidence', 4, 2);
            $table->decimal('entry_price', 12, 6);
            $table->decimal('stop_loss', 12, 6);
            $table->decimal('take_profit', 12, 6);
            $table->text('reasoning');
            $table->boolean('was_executed')->default(false);
            $table->string('rejection_reason')->nullable();
            $table->json('indicator_snapshot');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signals');
    }
};
