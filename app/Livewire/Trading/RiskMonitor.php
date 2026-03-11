<?php

namespace App\Livewire\Trading;

use App\Models\BotState;
use App\Models\Trade;
use App\Services\Broker\OandaClient;
use Livewire\Component;

class RiskMonitor extends Component
{
    public float $dailyPnl = 0;

    public float $dailyPnlPct = 0;

    public float $maxDailyLoss = 0;

    public float $drawdown = 0;

    public float $maxDrawdown = 0;

    public int $openTrades = 0;

    public int $maxOpenTrades = 0;

    public int $riskScore = 0;

    public function mount(): void
    {
        $this->maxDailyLoss = config('trading.risk.max_daily_loss') * 100;
        $this->maxDrawdown = config('trading.risk.max_drawdown') * 100;
        $this->maxOpenTrades = config('trading.risk.max_open_trades');
        $this->refresh();
    }

    public function refresh(): void
    {
        $this->dailyPnl = (float) Trade::closed()->today()->sum('pnl');
        $this->openTrades = Trade::open()->count();

        try {
            $broker = app(OandaClient::class);
            $account = $broker->getAccountInfo();
            $balance = (float) ($account['balance'] ?? 0);
            $peakBalance = BotState::getPeakBalance();

            $this->dailyPnlPct = $balance > 0 ? abs(min(0, $this->dailyPnl)) / $balance * 100 : 0;
            $this->drawdown = $peakBalance > 0 ? (($peakBalance - $balance) / $peakBalance) * 100 : 0;
        } catch (\Exception) {
            // Fallback
        }

        // Risk Score (0-100, höher = riskanter)
        $dailyRisk = $this->maxDailyLoss > 0 ? ($this->dailyPnlPct / $this->maxDailyLoss) * 33 : 0;
        $drawdownRisk = $this->maxDrawdown > 0 ? ($this->drawdown / $this->maxDrawdown) * 33 : 0;
        $tradeRisk = $this->maxOpenTrades > 0 ? ($this->openTrades / $this->maxOpenTrades) * 34 : 0;
        $this->riskScore = (int) min(100, $dailyRisk + $drawdownRisk + $tradeRisk);
    }

    public function render()
    {
        return view('livewire.trading.risk-monitor');
    }
}
