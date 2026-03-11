<?php

namespace App\Services\Indicators;

use Illuminate\Support\Collection;

class SMA
{
    /**
     * Simple Moving Average berechnen
     *
     * @return array{value: float, previous: float, signal: string, values: array}
     */
    public function calculate(Collection $candles, int $period = 20): array
    {
        $closes = $candles->pluck('close')->toArray();
        $count = count($closes);

        if ($count < $period + 1) {
            return ['value' => 0, 'previous' => 0, 'signal' => 'neutral', 'values' => []];
        }

        $values = [];
        for ($i = $period - 1; $i < $count; $i++) {
            $slice = array_slice($closes, $i - $period + 1, $period);
            $values[] = array_sum($slice) / $period;
        }

        $current = end($values);
        $previous = $values[count($values) - 2] ?? $current;
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
            'values' => $values,
        ];
    }
}
