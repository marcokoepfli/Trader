<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StrategyScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'strategy', 'score', 'win_rate', 'avg_pnl', 'profit_factor',
        'total_trades', 'wins', 'losses', 'consecutive_losses',
        'on_cooldown', 'cooldown_until',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'win_rate' => 'float',
            'avg_pnl' => 'float',
            'profit_factor' => 'float',
            'on_cooldown' => 'boolean',
            'cooldown_until' => 'datetime',
        ];
    }

    public function isAvailable(): bool
    {
        if ($this->on_cooldown && $this->cooldown_until && $this->cooldown_until->isFuture()) {
            return false;
        }

        // Cooldown abgelaufen → automatisch deaktivieren
        if ($this->on_cooldown && $this->cooldown_until && $this->cooldown_until->isPast()) {
            $this->update(['on_cooldown' => false, 'cooldown_until' => null]);

            return true;
        }

        return $this->score >= config('trading.strategy.min_score_to_trade');
    }
}
