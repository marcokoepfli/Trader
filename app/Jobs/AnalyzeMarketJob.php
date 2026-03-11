<?php

namespace App\Jobs;

use App\Events\SignalGenerated;
use App\Models\BotState;
use App\Models\Signal;
use App\Services\Broker\OandaClient;
use App\Services\Indicators\IndicatorService;
use App\Services\RiskManager;
use App\Services\Strategies\StrategyAggregator;
use App\Services\TradeExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzeMarketJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(
        OandaClient $broker,
        IndicatorService $indicatorService,
        StrategyAggregator $aggregator,
        RiskManager $riskManager,
        TradeExecutor $executor,
    ): void {
        $log = Log::channel('trading');

        if (! BotState::isRunning() || BotState::isPaused()) {
            // Pause prüfen und ggf. aufheben
            $pauseUntil = BotState::getValue('pause_until');
            if ($pauseUntil && now()->greaterThan($pauseUntil)) {
                BotState::setValue('is_paused', 'false');
                BotState::setValue('pause_until', '');
                BotState::setValue('pause_reason', '');
                $log->info('[BOT] Pause aufgehoben');
            } else {
                return;
            }
        }

        $pairs = config('trading.pairs');
        $timeframe = config('trading.timeframe');
        $higherTf = config('trading.higher_timeframe');
        $lookback = config('trading.learning.lookback_bars');

        BotState::setValue('last_analysis', now()->toIso8601String());

        foreach ($pairs as $pair) {
            try {
                // Candlestick-Daten holen
                $candles = $broker->getCandles($pair, $timeframe, $lookback);
                $h4Candles = $broker->getCandles($pair, $higherTf, 100);

                if ($candles->count() < 50) {
                    $log->warning("[ANALYZE] {$pair} — Nicht genug Daten ({$candles->count()} Candles)");

                    continue;
                }

                // Indikatoren berechnen
                $indicators = $indicatorService->calculateAll($candles);
                $h4Indicators = $h4Candles->count() >= 50
                    ? $indicatorService->calculateAll($h4Candles)
                    : [];

                $marketCondition = $indicators['market_condition'] ?? 'ranging';
                $session = $candles->last()['session'] ?? 'unknown';

                // Strategien analysieren (Confluence)
                $signal = $aggregator->analyze($indicators, $candles, $h4Indicators);

                if ($signal) {
                    // Risk-Check
                    $riskCheck = $riskManager->checkPreTradeConditions($signal, $pair);

                    if ($riskCheck['passed']) {
                        $confluenceScore = $signal->confidence;
                        $trade = $executor->execute(
                            $signal,
                            $pair,
                            $indicators,
                            $session,
                            $marketCondition,
                            $confluenceScore,
                        );

                        if ($trade) {
                            $log->info("[ANALYZE] {$pair} — Trade eröffnet: {$trade->direction} @ {$trade->entry_price}");
                        }
                    } else {
                        // Signal speichern mit Ablehnungsgrund
                        $signalModel = Signal::query()->create([
                            'pair' => $pair,
                            'strategy' => $signal->strategy,
                            'direction' => $signal->direction,
                            'confidence' => $signal->confidence,
                            'entry_price' => $signal->entryPrice,
                            'stop_loss' => $signal->stopLoss,
                            'take_profit' => $signal->takeProfit,
                            'reasoning' => $signal->reasoning,
                            'was_executed' => false,
                            'rejection_reason' => implode('; ', $riskCheck['failures']),
                            'indicator_snapshot' => $indicators,
                        ]);

                        SignalGenerated::dispatch($signalModel);
                    }
                }
            } catch (\Exception $e) {
                $log->error("[ANALYZE] {$pair} — Fehler: {$e->getMessage()}", [
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }
}
