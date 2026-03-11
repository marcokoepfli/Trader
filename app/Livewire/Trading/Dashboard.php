<?php

namespace App\Livewire\Trading;

use App\Models\BotState;
use App\Models\Trade;
use App\Services\Broker\OandaClient;
use Livewire\Component;

class Dashboard extends Component
{
    public float $balance = 0;

    public float $unrealizedPnl = 0;

    public float $todayPnl = 0;

    public int $openTradesCount = 0;

    public int $totalTrades = 0;

    public float $winRate = 0;

    public string $environment = 'practice';

    public bool $isRunning = false;

    public function mount(): void
    {
        $this->refreshData();
    }

    public function refreshData(): void
    {
        $this->isRunning = BotState::isRunning();
        $this->environment = BotState::getValue('environment', 'practice');
        $this->openTradesCount = Trade::open()->count();
        $this->todayPnl = (float) Trade::closed()->today()->sum('pnl');

        $closedTrades = Trade::closed()->count();
        $wins = Trade::wins()->count();
        $this->totalTrades = $closedTrades;
        $this->winRate = $closedTrades > 0 ? round(($wins / $closedTrades) * 100, 1) : 0;

        try {
            $broker = app(OandaClient::class);
            $account = $broker->getAccountInfo();
            $this->balance = (float) ($account['balance'] ?? 0);
            $this->unrealizedPnl = (float) ($account['unrealizedPL'] ?? 0);
        } catch (\Exception) {
            // Fallback
        }
    }

    public function render()
    {
        return view('livewire.trading.dashboard');
    }
}
