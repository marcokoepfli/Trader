<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description');
            $table->string('type');
            $table->json('conditions');
            $table->text('reason');
            $table->enum('source', ['auto', 'manual'])->default('auto');
            $table->boolean('active')->default(true);
            $table->integer('trades_prevented')->default(0);
            $table->decimal('estimated_savings', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_rules');
    }
};
