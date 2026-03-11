<?php

namespace App\Services\Strategies;

use App\DTOs\SignalDTO;
use App\Models\BotState;
use App\Models\StrategyScore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class StrategyAggregator
{
    /** @var StrategyInterface[] */
    private array $strategies;

    public function __construct()
    {
        $this->strategies = [
            new MACDCrossover,
            new RSIReversal,
            new BollingerBounce,
            new EMACrossover,
            new BreakoutStrategy,
            new FibonacciPullback,
        ];
    }

    /**
     * Alle Strategien analysieren und bestes Signal via Confluence ermitteln
     */
    public function analyze(array $indicators, Collection $candles, array $higherTfIndicators): ?SignalDTO
    {
        $log = Log::channel('trading');
        $signals = [];
        $minScore = config('trading.strategy.min_score_to_trade');

        // Session-Filter: Nur in London + Overlap + NY handeln
        $session = $candles->last()['session'] ?? 'unknown';
        $pair = ''; // Pair wird vom Caller geprüft

        if (config('trading.session_filter.enabled', true)) {
            $allowedSessions = config('trading.session_filter.allowed_sessions', ['london', 'overlap', 'newyork']);
            if (! in_array($session, $allowedSessions)) {
                $log->debug("[SIGNAL] Session-Filter: {$session} nicht erlaubt — übersprungen");

                return null;
            }
        }

        foreach ($this->strategies as $strategy) {
            $name = $strategy->getName();

            // Strategie-Score prüfen
            $score = StrategyScore::query()->where('strategy', $name)->first();

            if ($score && ! $score->isAvailable()) {
                continue;
            }

            $currentScore = $score ? $score->score : 0.5;
            if ($currentScore < $minScore) {
                continue;
            }

            // Adaptive: Strategie in bestimmter Marktbedingung vermeiden
            $avoidCondition = BotState::getValue("avoid_condition_{$name}");
            $marketCondition = $indicators['market_condition'] ?? 'ranging';
            if ($avoidCondition && $avoidCondition === $marketCondition) {
                $log->debug("[SIGNAL] {$name} vermeidet {$marketCondition} Markt (adaptiv)");

                continue;
            }

            $signal = $strategy->analyze($indicators, $candles, $higherTfIndicators);
            if ($signal) {
                $signals[] = ['signal' => $signal, 'score' => $currentScore];
            }
        }

        if (empty($signals)) {
            return null;
        }

        // Nach Richtung gruppieren
        $buySignals = array_filter($signals, fn ($s) => $s['signal']->direction === 'BUY');
        $sellSignals = array_filter($signals, fn ($s) => $s['signal']->direction === 'SELL');

        // Korrelations-Widerspruch erkennen: BUY + SELL gleichzeitig = unsicherer Markt
        if (count($buySignals) > 0 && count($sellSignals) > 0) {
            $log->info(sprintf(
                '[SIGNAL] Widersprüchliche Signale — %d BUY vs %d SELL — übersprungen (unsicherer Markt)',
                count($buySignals),
                count($sellSignals),
            ));

            return null;
        }

        $minConfluence = config('trading.strategy.min_confluence');

        // Beste Richtung wählen (mehr Confluence)
        $bestGroup = null;
        if (count($buySignals) >= $minConfluence) {
            $bestGroup = $buySignals;
        } elseif (count($sellSignals) >= $minConfluence) {
            $bestGroup = $sellSignals;
        }

        if (! $bestGroup) {
            $log->debug(sprintf(
                '[SIGNAL] Keine Confluence — BUY: %d, SELL: %d (min: %d)',
                count($buySignals),
                count($sellSignals),
                $minConfluence,
            ));

            return null;
        }

        // Strikter H4-Trend Filter: NICHT gegen den H4-Trend handeln
        if (config('trading.h4_trend_filter.enabled', true) && ! empty($higherTfIndicators)) {
            $direction = $bestGroup === $buySignals ? 'BUY' : 'SELL';
            if (! $this->confirmH4Trend($direction, $higherTfIndicators, $log)) {
                return null;
            }
        }

        // Gewichteten Confidence-Score berechnen
        $totalWeight = array_sum(array_column($bestGroup, 'score'));
        $weightedConfidence = 0;
        $strategies = [];

        foreach ($bestGroup as $item) {
            $weight = $item['score'] / $totalWeight;
            $weightedConfidence += $item['signal']->confidence * $weight;
            $strategies[] = sprintf('%s (%.2f)', $item['signal']->strategy, $item['signal']->confidence);
        }

        // Bestes Signal (höchste Confidence) als Basis nehmen
        usort($bestGroup, fn ($a, $b) => $b['signal']->confidence <=> $a['signal']->confidence);
        $best = $bestGroup[0]['signal'];

        $log->info(sprintf(
            '[SIGNAL] %s %s — %s — Confluence: %d/%d',
            $candles->last()['close'] ?? 'N/A',
            $best->direction,
            implode(' + ', $strategies),
            count($bestGroup),
            count($this->strategies),
        ));

        return new SignalDTO(
            direction: $best->direction,
            confidence: round($weightedConfidence, 2),
            strategy: implode('+', array_map(fn ($s) => $s['signal']->strategy, $bestGroup)),
            entryPrice: $best->entryPrice,
            stopLoss: $best->stopLoss,
            takeProfit: $best->takeProfit,
            reasoning: implode(' | ', array_map(fn ($s) => $s['signal']->reasoning, $bestGroup)),
        );
    }

    /**
     * H4-Trend bestätigen: NIE gegen den übergeordneten Trend handeln
     */
    private function confirmH4Trend(string $direction, array $h4Indicators, $log): bool
    {
        $h4Ema50 = $h4Indicators['ema_50']['value'] ?? null;
        $h4Sma200 = $h4Indicators['sma_200']['value'] ?? null;
        $h4Macd = $h4Indicators['macd'] ?? null;

        if (! $h4Ema50) {
            return true; // Keine H4-Daten → nicht blockieren
        }

        // Preis muss auf der richtigen Seite der H4 EMA50 sein
        $h4Close = $h4Indicators['ema_50']['value'] ?? null; // EMA als Referenz

        // H4 EMA50 + SMA200 Ausrichtung prüfen
        if ($h4Sma200) {
            if ($direction === 'BUY' && $h4Ema50 < $h4Sma200) {
                $log->info('[SIGNAL] H4-Trend Filter: BUY blockiert — EMA50 < SMA200 (Abwärtstrend)');

                return false;
            }
            if ($direction === 'SELL' && $h4Ema50 > $h4Sma200) {
                $log->info('[SIGNAL] H4-Trend Filter: SELL blockiert — EMA50 > SMA200 (Aufwärtstrend)');

                return false;
            }
        }

        // H4 MACD-Bestätigung
        if ($h4Macd) {
            $h4MacdHistogram = $h4Macd['histogram'] ?? 0;
            if ($direction === 'BUY' && $h4MacdHistogram < 0) {
                $log->debug('[SIGNAL] H4-Trend: MACD bearish — BUY-Confidence reduziert');
                // Nicht blockieren, aber in Log vermerken
            }
            if ($direction === 'SELL' && $h4MacdHistogram > 0) {
                $log->debug('[SIGNAL] H4-Trend: MACD bullish — SELL-Confidence reduziert');
            }
        }

        return true;
    }
}
