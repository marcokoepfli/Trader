<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'report_date', 'starting_balance', 'ending_balance',
        'daily_pnl', 'daily_pnl_pct', 'trades_opened', 'trades_closed',
        'wins', 'losses', 'strategy_breakdown', 'pair_breakdown', 'new_rules',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'starting_balance' => 'float',
            'ending_balance' => 'float',
            'daily_pnl' => 'float',
            'daily_pnl_pct' => 'float',
            'strategy_breakdown' => 'array',
            'pair_breakdown' => 'array',
            'new_rules' => 'array',
        ];
    }
}
