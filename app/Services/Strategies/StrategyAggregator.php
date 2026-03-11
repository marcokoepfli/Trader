<?php

namespace App\Services\Strategies;

use App\DTOs\SignalDTO;
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

        $minConfluence = config('trading.strategy.min_confluence');

        // Beste Richtung wählen (mehr Confluence)
        $bestGroup = null;
        if (count($buySignals) >= $minConfluence && count($buySignals) >= count($sellSignals)) {
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
}
