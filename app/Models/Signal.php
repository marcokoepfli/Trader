<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Signal extends Model
{
    use HasFactory;

    protected $fillable = [
        'pair', 'strategy', 'direction', 'confidence', 'entry_price',
        'stop_loss', 'take_profit', 'reasoning', 'was_executed',
        'rejection_reason', 'indicator_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'indicator_snapshot' => 'array',
            'confidence' => 'float',
            'entry_price' => 'float',
            'stop_loss' => 'float',
            'take_profit' => 'float',
            'was_executed' => 'boolean',
        ];
    }

    public function scopeRecent($query, int $limit = 20)
    {
        return $query->latest()->limit($limit);
    }
}
