<?php

namespace App\Livewire\Trading;

use App\Models\StrategyScore;
use Livewire\Component;

class StrategyScores extends Component
{
    public function render()
    {
        $scores = StrategyScore::query()->orderByDesc('score')->get();

        return view('livewire.trading.strategy-scores', compact('scores'));
    }
}
