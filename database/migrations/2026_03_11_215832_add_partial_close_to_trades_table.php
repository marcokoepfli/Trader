<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->boolean('partial_close_done')->default(false)->after('hit_take_profit');
            $table->integer('original_position_size')->nullable()->after('position_size');
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropColumn(['partial_close_done', 'original_position_size']);
        });
    }
};
