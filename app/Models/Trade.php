<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Trade extends Model
{
    use HasFactory;

    protected $fillable = [
        'oanda_trade_id', 'pair', 'direction', 'strategy', 'entry_price', 'exit_price',
        'stop_loss', 'take_profit', 'position_size', 'pnl', 'pnl_pct', 'result',
        'confluence_score', 'session', 'market_condition', 'indicators_at_entry',
        'indicators_at_exit', 'max_favorable', 'max_adverse', 'hit_stop_loss',
        'hit_take_profit', 'slippage', 'reasoning', 'exit_notes', 'opened_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'indicators_at_entry' => 'array',
            'indicators_at_exit' => 'array',
            'entry_price' => 'float',
            'exit_price' => 'float',
            'stop_loss' => 'float',
            'take_profit' => 'float',
            'pnl' => 'float',
            'pnl_pct' => 'float',
            'confluence_score' => 'float',
            'max_favorable' => 'float',
            'max_adverse' => 'float',
            'slippage' => 'float',
            'hit_stop_loss' => 'boolean',
            'hit_take_profit' => 'boolean',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function scopeOpen($query)
    {
        return $query->where('result', 'OPEN');
    }

    public function scopeClosed($query)
    {
        return $query->whereIn('result', ['WIN', 'LOSS']);
    }

    public function scopeWins($query)
    {
        return $query->where('result', 'WIN');
    }

    public function scopeLosses($query)
    {
        return $query->where('result', 'LOSS');
    }

    public function scopeForPair($query, string $pair)
    {
        return $query->where('pair', $pair);
    }

    public function scopeForStrategy($query, string $strategy)
    {
        return $query->where('strategy', $strategy);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('opened_at', today());
    }

    public function isOpen(): bool
    {
        return $this->result === 'OPEN';
    }
}
