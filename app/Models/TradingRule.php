<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TradingRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'description', 'type', 'conditions', 'reason',
        'source', 'active', 'trades_prevented', 'estimated_savings',
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'active' => 'boolean',
            'estimated_savings' => 'float',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /** Prüfe ob diese Regel einen Trade blockieren würde */
    public function blocks(string $strategy, string $pair, string $session, array $indicators): bool
    {
        if (! $this->active) {
            return false;
        }

        $conditions = $this->conditions;

        return match ($this->type) {
            'session_block' => ($conditions['strategy'] ?? null) === $strategy
                && ($conditions['session'] ?? null) === $session,
            'indicator_filter' => $this->checkIndicatorCondition($conditions, $indicators),
            'strategy_pause' => ($conditions['strategy'] ?? null) === $strategy,
            'pair_block' => ($conditions['pair'] ?? null) === $pair,
            'market_condition' => isset($conditions['market_condition'])
                && ($conditions['market_condition'] ?? null) === ($indicators['market_condition'] ?? null),
            default => false,
        };
    }

    private function checkIndicatorCondition(array $conditions, array $indicators): bool
    {
        $indicator = $conditions['indicator'] ?? null;
        $operator = $conditions['operator'] ?? null;
        $value = $conditions['value'] ?? null;
        $actual = $indicators[$indicator]['value'] ?? null;

        if ($actual === null || $value === null) {
            return false;
        }

        return match ($operator) {
            '<' => $actual < $value,
            '>' => $actual > $value,
            '<=' => $actual <= $value,
            '>=' => $actual >= $value,
            '=' => $actual == $value,
            default => false,
        };
    }
}
