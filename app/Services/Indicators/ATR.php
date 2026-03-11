<?php

namespace App\Services\Indicators;

use Illuminate\Support\Collection;

class ATR
{
    /**
     * Average True Range berechnen (Wilder's Smoothing)
     *
     * @return array{value: float, previous: float, signal: string, values: array}
     */
    public function calculate(Collection $candles, int $period = 14): array
    {
        $data = $candles->toArray();
        $count = count($data);

        if ($count < $period + 1) {
            return ['value' => 0, 'previous' => 0, 'signal' => 'neutral', 'values' => []];
        }

        // True Range berechnen
        $trueRanges = [];
        for ($i = 1; $i < $count; $i++) {
            $high = $data[$i]['high'];
            $low = $data[$i]['low'];
            $prevClose = $data[$i - 1]['close'];

            $tr = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose)
            );
            $trueRanges[] = $tr;
        }

        // Erster ATR = Durchschnitt der ersten N True Ranges
        $atr = array_sum(array_slice($trueRanges, 0, $period)) / $period;
        $atrValues = [$atr];

        // Wilder's Smoothing
        for ($i = $period; $i < count($trueRanges); $i++) {
            $atr = (($atr * ($period - 1)) + $trueRanges[$i]) / $period;
            $atrValues[] = $atr;
        }

        $current = end($atrValues);
        $previous = $atrValues[count($atrValues) - 2] ?? $current;

        // ATR steigend = volatiler Markt
        $signal = 'neutral';
        if ($current > $previous * 1.1) {
            $signal = 'volatile';
        } elseif ($current < $previous * 0.9) {
            $signal = 'quiet';
        }

        return [
            'value' => round($current, 6),
            'previous' => round($previous, 6),
            'signal' => $signal,
            'values' => $atrValues,
        ];
    }
}
