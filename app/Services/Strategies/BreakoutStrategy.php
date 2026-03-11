<?php

namespace App\Services\Strategies;

use App\DTOs\SignalDTO;
use Illuminate\Support\Collection;

class BreakoutStrategy implements StrategyInterface
{
    public function getName(): string
    {
        return 'BreakoutStrategy';
    }

    public function analyze(array $indicators, Collection $candles, array $higherTfIndicators): ?SignalDTO
    {
        $atr = $indicators['atr'] ?? null;
        $adx = $indicators['adx'] ?? null;

        if (! $atr || ! $adx || $candles->count() < 50) {
            return null;
        }

        $lastCandle = $candles->last();
        $close = $lastCandle['close'];
        $atrValue = $atr['value'];
        $atrPrev = $atr['previous'];

        // ATR muss steigend sein (zunehmende Volatilität)
        if ($atrValue <= $atrPrev) {
            return null;
        }

        // Support/Resistance aus Swing Highs/Lows berechnen
        $recentCandles = $candles->slice(-50)->values();
        $levels = $this->findSupportResistance($recentCandles);

        if (empty($levels['resistance']) && empty($levels['support'])) {
            return null;
        }

        $tolerance = $atrValue * 0.3;

        // BUY: Breakout über Resistance
        foreach ($levels['resistance'] as $level) {
            if ($level['touches'] >= 2 && $close > $level['price'] && $close < $level['price'] + $tolerance * 3) {
                $sl = $close - ($atrValue * config('trading.risk.atr_sl_multiplier'));
                $slDistance = abs($close - $sl);
                $tp = $close + ($slDistance * config('trading.risk.min_rr_ratio'));

                return new SignalDTO(
                    direction: 'BUY',
                    confidence: min(0.8, 0.5 + ($level['touches'] * 0.1)),
                    strategy: $this->getName(),
                    entryPrice: $close,
                    stopLoss: $sl,
                    takeProfit: $tp,
                    reasoning: "Breakout über Resistance {$level['price']} ({$level['touches']}x getestet), ATR steigend",
                );
            }
        }

        // SELL: Breakout unter Support
        foreach ($levels['support'] as $level) {
            if ($level['touches'] >= 2 && $close < $level['price'] && $close > $level['price'] - $tolerance * 3) {
                $sl = $close + ($atrValue * config('trading.risk.atr_sl_multiplier'));
                $slDistance = abs($sl - $close);
                $tp = $close - ($slDistance * config('trading.risk.min_rr_ratio'));

                return new SignalDTO(
                    direction: 'SELL',
                    confidence: min(0.8, 0.5 + ($level['touches'] * 0.1)),
                    strategy: $this->getName(),
                    entryPrice: $close,
                    stopLoss: $sl,
                    takeProfit: $tp,
                    reasoning: "Breakout unter Support {$level['price']} ({$level['touches']}x getestet), ATR steigend",
                );
            }
        }

        return null;
    }

    /**
     * Support/Resistance Levels aus Swing Highs/Lows finden
     */
    private function findSupportResistance(Collection $candles): array
    {
        $data = $candles->toArray();
        $count = count($data);
        $swingHighs = [];
        $swingLows = [];

        // Swing Points finden (lokale Extrema)
        for ($i = 2; $i < $count - 2; $i++) {
            if ($data[$i]['high'] > $data[$i - 1]['high'] &&
                $data[$i]['high'] > $data[$i - 2]['high'] &&
                $data[$i]['high'] > $data[$i + 1]['high'] &&
                $data[$i]['high'] > $data[$i + 2]['high']) {
                $swingHighs[] = $data[$i]['high'];
            }

            if ($data[$i]['low'] < $data[$i - 1]['low'] &&
                $data[$i]['low'] < $data[$i - 2]['low'] &&
                $data[$i]['low'] < $data[$i + 1]['low'] &&
                $data[$i]['low'] < $data[$i + 2]['low']) {
                $swingLows[] = $data[$i]['low'];
            }
        }

        // Levels clustern und Touches zählen
        $avgRange = collect($data)->avg(fn ($c) => $c['high'] - $c['low']);
        $clusterTolerance = $avgRange * 1.5;

        return [
            'resistance' => $this->clusterLevels($swingHighs, $clusterTolerance),
            'support' => $this->clusterLevels($swingLows, $clusterTolerance),
        ];
    }

    /**
     * Preis-Levels zu Clustern zusammenfassen
     */
    private function clusterLevels(array $prices, float $tolerance): array
    {
        if (empty($prices)) {
            return [];
        }

        sort($prices);
        $clusters = [];
        $currentCluster = [$prices[0]];

        for ($i = 1; $i < count($prices); $i++) {
            if ($tolerance > $prices[$i] - end($currentCluster)) {
                $currentCluster[] = $prices[$i];
            } else {
                $clusters[] = [
                    'price' => round(array_sum($currentCluster) / count($currentCluster), 6),
                    'touches' => count($currentCluster),
                ];
                $currentCluster = [$prices[$i]];
            }
        }

        $clusters[] = [
            'price' => round(array_sum($currentCluster) / count($currentCluster), 6),
            'touches' => count($currentCluster),
        ];

        // Nach Touches sortieren
        usort($clusters, fn ($a, $b) => $b['touches'] <=> $a['touches']);

        return $clusters;
    }
}
