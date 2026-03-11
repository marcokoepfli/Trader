<?php

namespace App\Jobs;

use App\Events\TradeClosed;
use App\Models\BotState;
use App\Models\Trade;
use App\Services\Broker\OandaClient;
use App\Services\Indicators\IndicatorService;
use App\Services\RiskManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ManageOpenTradesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function handle(OandaClient $broker, RiskManager $riskManager, IndicatorService $indicatorService): void
    {
        $log = Log::channel('trading');

        if (! BotState::isRunning()) {
            return;
        }

        $openTrades = Trade::open()->get();

        if ($openTrades->isEmpty()) {
            return;
        }

        // Offene Trades bei OANDA abfragen
        $oandaTrades = $broker->getOpenTrades();
        $oandaTradeIds = $oandaTrades->pluck('id')->toArray();

        foreach ($openTrades as $trade) {
            try {
                // Prüfe ob Trade bei OANDA noch offen
                if ($trade->oanda_trade_id && ! in_array($trade->oanda_trade_id, $oandaTradeIds)) {
                    // Trade wurde geschlossen (SL/TP getroffen)
                    $this->syncClosedTrade($trade, $broker, $indicatorService);

                    continue;
                }

                // Aktuellen Preis holen
                $price = $broker->getCurrentPrice($trade->pair);
                $currentPrice = $trade->direction === 'BUY' ? $price['bid'] : $price['ask'];

                // Trade managen (Trailing Stop etc.)
                $riskManager->manageOpenTrade($trade, $currentPrice);

            } catch (\Exception $e) {
                $log->error("[MANAGE] Fehler bei Trade {$trade->id}: {$e->getMessage()}");
            }
        }

        // Tägliche Limits und Drawdown prüfen
        $riskManager->checkDailyLimits();

        $account = $broker->getAccountInfo();
        $balance = (float) ($account['balance'] ?? 0);
        if ($balance > 0) {
            $riskManager->checkDrawdown($balance);
        }
    }

    /**
     * Geschlossenen Trade mit OANDA synchronisieren
     */
    private function syncClosedTrade(Trade $trade, OandaClient $broker, IndicatorService $indicatorService): void
    {
        $log = Log::channel('trading');

        // Aktuellen Preis als Exit-Preis verwenden
        $price = $broker->getCurrentPrice($trade->pair);
        $exitPrice = $trade->direction === 'BUY' ? $price['bid'] : $price['ask'];

        // P&L berechnen
        if ($trade->direction === 'BUY') {
            $pnl = ($exitPrice - $trade->entry_price) * $trade->position_size;
        } else {
            $pnl = ($trade->entry_price - $exitPrice) * $trade->position_size;
        }

        $account = $broker->getAccountInfo();
        $balance = (float) ($account['balance'] ?? 0);
        $pnlPct = $balance > 0 ? ($pnl / $balance) * 100 : 0;

        // Exit-Indikatoren berechnen
        $candles = $broker->getCandles($trade->pair, config('trading.timeframe'), 50);
        $exitIndicators = $candles->count() >= 20 ? $indicatorService->calculateAll($candles) : [];

        // Hit SL oder TP?
        $hitSl = false;
        $hitTp = false;

        if ($trade->direction === 'BUY') {
            $hitSl = $exitPrice <= $trade->stop_loss;
            $hitTp = $exitPrice >= $trade->take_profit;
        } else {
            $hitSl = $exitPrice >= $trade->stop_loss;
            $hitTp = $exitPrice <= $trade->take_profit;
        }

        $result = $pnl >= 0 ? 'WIN' : 'LOSS';

        $trade->update([
            'exit_price' => $exitPrice,
            'pnl' => round($pnl, 2),
            'pnl_pct' => round($pnlPct, 4),
            'result' => $result,
            'hit_stop_loss' => $hitSl,
            'hit_take_profit' => $hitTp,
            'indicators_at_exit' => $exitIndicators,
            'exit_notes' => $hitSl ? 'Stop Loss getroffen' : ($hitTp ? 'Take Profit erreicht' : 'Manuell geschlossen'),
            'closed_at' => now(),
        ]);

        $log->info(sprintf(
            '[MANAGE] Trade %s synchronisiert — %s $%.2f',
            $trade->pair,
            $result,
            $pnl,
        ));

        TradeClosed::dispatch($trade->fresh());
    }
}
