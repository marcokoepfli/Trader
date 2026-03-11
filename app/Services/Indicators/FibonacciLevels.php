<?php

namespace App\Services\Indicators;

use Illuminate\Support\Collection;

class FibonacciLevels
{
    /**
     * Fibonacci Retracement Levels berechnen
     *
     * @return array{levels: array, swing_high: float, swing_low: float, trend: string, signal: string, value: float}
     */
    public function calculate(Collection $candles, int $lookback = 50): array
    {
        $data = $candles->toArray();
        $count = count($data);
        $slice = array_slice($data, -min($lookback, $count));

        if (count($slice) < 10) {
            return ['levels' => [], 'swing_high' => 0, 'swing_low' => 0, 'trend' => 'neutral', 'signal' => 'neutral', 'value' => 0];
        }

        // Swing High und Low finden
        $highs = array_column($slice, 'high');
        $lows = array_column($slice, 'low');
        $swingHigh = max($highs);
        $swingLow = min($lows);

        $highIndex = array_search($swingHigh, $highs);
        $lowIndex = array_search($swingLow, $lows);

        // Trend bestimmen: High nach Low = Abwärtstrend, Low nach High = Aufwärtstrend
        $trend = $highIndex < $lowIndex ? 'bearish' : 'bullish';

        $range = $swingHigh - $swingLow;
        if ($range <= 0) {
            return ['levels' => [], 'swing_high' => $swingHigh, 'swing_low' => $swingLow, 'trend' => 'neutral', 'signal' => 'neutral', 'value' => 0];
        }

        // Fibonacci Levels
        $ratios = [0.236, 0.382, 0.500, 0.618, 0.786];
        $levels = [];

        foreach ($ratios as $ratio) {
            if ($trend === 'bullish') {
                // Aufwärtstrend: Retracement von oben nach unten
                $levels[$ratio] = round($swingHigh - ($range * $ratio), 6);
            } else {
                // Abwärtstrend: Retracement von unten nach oben
                $levels[$ratio] = round($swingLow + ($range * $ratio), 6);
            }
        }

        // Signal: Preis nahe an 61.8% Level?
        $lastClose = end($data)['close'];
        $fib618 = $levels[0.618];
        $tolerance = $range * 0.02; // 2% Toleranz

        $signal = 'neutral';
        if (abs($lastClose - $fib618) < $tolerance) {
            $signal = $trend === 'bullish' ? 'bullish' : 'bearish';
        }

        return [
            'levels' => $levels,
            'swing_high' => round($swingHigh, 6),
            'swing_low' => round($swingLow, 6),
            'trend' => $trend,
            'signal' => $signal,
            'value' => round($fib618, 6),
        ];
    }
}
