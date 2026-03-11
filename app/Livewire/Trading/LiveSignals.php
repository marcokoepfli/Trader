<?php

namespace App\Livewire\Trading;

use App\Models\Signal;
use Livewire\Component;

class LiveSignals extends Component
{
    public function render()
    {
        $signals = Signal::query()->latest()->limit(20)->get();

        return view('livewire.trading.live-signals', compact('signals'));
    }
}
