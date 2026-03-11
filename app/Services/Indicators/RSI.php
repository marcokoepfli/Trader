<?php

namespace App\Services\Indicators;

use Illuminate\Support\Collection;

class RSI
{
    /**
     * Relative Strength Index berechnen (Wilder's Smoothing)
     *
     * @return array{value: float, previous: float, signal: string}
     */
    public function calculate(Collection $candles, int $period = 14): array
    {
        $closes = $candles->pluck('close')->toArray();
        $count = count($closes);

        if ($count < $period + 2) {
            return ['value' => 50, 'previous' => 50, 'signal' => 'neutral'];
        }

        // Preisänderungen berechnen
        $changes = [];
        for ($i = 1; $i < $count; $i++) {
            $changes[] = $closes[$i] - $closes[$i - 1];
        }

        // Erste Durchschnittswerte (SMA)
        $gains = [];
        $losses = [];
        for ($i = 0; $i < $period; $i++) {
            if ($changes[$i] > 0) {
                $gains[] = $changes[$i];
                $losses[] = 0;
            } else {
                $gains[] = 0;
                $losses[] = abs($changes[$i]);
            }
        }

        $avgGain = array_sum($gains) / $period;
        $avgLoss = array_sum($losses) / $period;

        // Wilder's Smoothing für restliche Werte
        $rsiValues = [];
        $rsi = $avgLoss == 0 ? 100 : 100 - (100 / (1 + $avgGain / $avgLoss));
        $rsiValues[] = $rsi;

        for ($i = $period; $i < count($changes); $i++) {
            $gain = $changes[$i] > 0 ? $changes[$i] : 0;
            $loss = $changes[$i] < 0 ? abs($changes[$i]) : 0;

            $avgGain = ($avgGain * ($period - 1) + $gain) / $period;
            $avgLoss = ($avgLoss * ($period - 1) + $loss) / $period;

            $rsi = $avgLoss == 0 ? 100 : 100 - (100 / (1 + $avgGain / $avgLoss));
            $rsiValues[] = $rsi;
        }

        $current = end($rsiValues);
        $previous = $rsiValues[count($rsiValues) - 2] ?? $current;

        // Signal: Überverkauft = bullish, Überkauft = bearish
        $signal = 'neutral';
        if ($current < 30) {
            $signal = 'bullish';
        } elseif ($current > 70) {
            $signal = 'bearish';
        }

        return [
            'value' => round($current, 2),
            'previous' => round($previous, 2),
            'signal' => $signal,
        ];
    }
}
