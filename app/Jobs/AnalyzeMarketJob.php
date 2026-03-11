<?php

namespace App\Jobs;

use App\DTOs\SignalDTO;
use App\Events\SignalGenerated;
use App\Models\BotState;
use App\Models\Signal;
use App\Services\Broker\OandaClient;
use App\Services\Indicators\IndicatorService;
use App\Services\NewsFilter;
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
        NewsFilter $newsFilter,
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
                // News-Filter: Pair überspringen wenn High-Impact News anstehen
                if (config('trading.news_filter.enabled')) {
                    $newsCheck = $newsFilter->isBlocked($pair);
                    if ($newsCheck['blocked']) {
                        $log->info("[ANALYZE] {$pair} — Übersprungen: {$newsCheck['reason']}");

                        continue;
                    }
                }

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
                    // M15 Entry Refinement: Besserer Einstieg auf kleinerem Timeframe
                    if (config('trading.entry_refinement.enabled')) {
                        $signal = $this->refineEntryM15($broker, $indicatorService, $signal, $pair, $log);
                        if ($signal === null) {
                            $log->debug("[ANALYZE] {$pair} — M15 Refinement: Kein bestätigender Einstieg");

                            continue;
                        }
                    }

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

    /**
     * M15 Entry Refinement: Prüfe auf dem 15-Min-Chart ob ein besserer Einstieg möglich ist
     */
    private function refineEntryM15(
        OandaClient $broker,
        IndicatorService $indicatorService,
        SignalDTO $signal,
        string $pair,
        $log,
    ): ?SignalDTO {
        $m15Candles = $broker->getCandles($pair, config('trading.entry_refinement.timeframe', 'M15'), 20);

        if ($m15Candles->count() < 10) {
            return $signal; // Nicht genug M15-Daten, H1-Signal verwenden
        }

        $lastCandle = $m15Candles->last();
        $prevCandle = $m15Candles->slice(-2, 1)->first();

        if (! $lastCandle || ! $prevCandle) {
            return $signal;
        }

        // Prüfe ob die letzte M15-Kerze die Richtung bestätigt
        if (config('trading.entry_refinement.require_confirmation')) {
            $isBullishCandle = $lastCandle['close'] > $lastCandle['open'];
            $isBearishCandle = $lastCandle['close'] < $lastCandle['open'];

            if ($signal->direction === 'BUY' && ! $isBullishCandle) {
                return null; // M15 bestätigt nicht — kein Entry
            }

            if ($signal->direction === 'SELL' && ! $isBearishCandle) {
                return null; // M15 bestätigt nicht — kein Entry
            }
        }

        // Besseren Entry-Preis vom M15 verwenden
        $refinedEntry = $lastCandle['close'];

        // Engeren SL basierend auf M15-Swing setzen
        $recentLows = $m15Candles->slice(-5)->pluck('low');
        $recentHighs = $m15Candles->slice(-5)->pluck('high');

        if ($signal->direction === 'BUY') {
            $swingLow = $recentLows->min();
            $buffer = ($refinedEntry - $swingLow) * 0.1;
            $refinedSl = $swingLow - $buffer;

            // SL darf nicht weiter als der H1-SL sein (engerer SL = besseres R:R)
            if ($refinedSl > $signal->stopLoss && $refinedSl < $refinedEntry) {
                $log->debug(sprintf('[M15] %s BUY: SL verbessert %.5f → %.5f', $pair, $signal->stopLoss, $refinedSl));

                return new SignalDTO(
                    direction: $signal->direction,
                    confidence: $signal->confidence,
                    strategy: $signal->strategy,
                    entryPrice: $refinedEntry,
                    stopLoss: $refinedSl,
                    takeProfit: $signal->takeProfit,
                    reasoning: $signal->reasoning.' [M15 refined]',
                );
            }
        } else {
            $swingHigh = $recentHighs->max();
            $buffer = ($swingHigh - $refinedEntry) * 0.1;
            $refinedSl = $swingHigh + $buffer;

            if ($refinedSl < $signal->stopLoss && $refinedSl > $refinedEntry) {
                $log->debug(sprintf('[M15] %s SELL: SL verbessert %.5f → %.5f', $pair, $signal->stopLoss, $refinedSl));

                return new SignalDTO(
                    direction: $signal->direction,
                    confidence: $signal->confidence,
                    strategy: $signal->strategy,
                    entryPrice: $refinedEntry,
                    stopLoss: $refinedSl,
                    takeProfit: $signal->takeProfit,
                    reasoning: $signal->reasoning.' [M15 refined]',
                );
            }
        }

        // M15 bestätigt, aber kein besserer SL — Original-Signal verwenden
        return $signal;
    }
}
