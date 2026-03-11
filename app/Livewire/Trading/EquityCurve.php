<?php

namespace App\Livewire\Trading;

use App\Models\Trade;
use Livewire\Component;

class EquityCurve extends Component
{
    public array $labels = [];

    public array $equity = [];

    public array $drawdown = [];

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        $trades = Trade::closed()->orderBy('closed_at')->get();
        $cumPnl = 0;
        $peak = 0;

        $this->labels = [];
        $this->equity = [];
        $this->drawdown = [];

        foreach ($trades as $trade) {
            $cumPnl += $trade->pnl;
            if ($cumPnl > $peak) {
                $peak = $cumPnl;
            }

            $dd = $peak > 0 ? (($peak - $cumPnl) / $peak) * 100 : 0;

            $this->labels[] = $trade->closed_at?->format('d.m H:i') ?? '';
            $this->equity[] = round($cumPnl, 2);
            $this->drawdown[] = round($dd, 2);
        }
    }

    public function render()
    {
        return view('livewire.trading.equity-curve');
    }
}
