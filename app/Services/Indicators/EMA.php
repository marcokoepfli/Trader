<?php

namespace App\Services\Indicators;

use Illuminate\Support\Collection;

class EMA
{
    /**
     * Exponential Moving Average berechnen
     *
     * @return array{value: float, previous: float, signal: string, values: array}
     */
    public function calculate(Collection $candles, int $period = 21): array
    {
        $closes = $candles->pluck('close')->toArray();
        $count = count($closes);

        if ($count < $period + 1) {
            return ['value' => 0, 'previous' => 0, 'signal' => 'neutral', 'values' => []];
        }

        $multiplier = 2.0 / ($period + 1);

        // Erster EMA-Wert = SMA der ersten {period} Werte
        $sma = array_sum(array_slice($closes, 0, $period)) / $period;
        $emaValues = [$sma];

        // EMA fortlaufend berechnen
        for ($i = $period; $i < $count; $i++) {
            $ema = ($closes[$i] - end($emaValues)) * $multiplier + end($emaValues);
            $emaValues[] = $ema;
        }

        $current = end($emaValues);
        $previous = $emaValues[count($emaValues) - 2] ?? $current;
        $lastClose = end($closes);

        $signal = 'neutral';
        if ($lastClose > $current) {
            $signal = 'bullish';
        } elseif ($lastClose < $current) {
            $signal = 'bearish';
        }

        return [
            'value' => round($current, 6),
            'previous' => round($previous, 6),
            'signal' => $signal,
            'values' => $emaValues,
        ];
    }
}
