<?php

namespace App\Services\Indicators;

use Illuminate\Support\Collection;

class Stochastic
{
    /**
     * Stochastic Oszillator berechnen (%K, %D)
     *
     * @return array{k: float, d: float, previous_k: float, previous_d: float, signal: string, value: float}
     */
    public function calculate(Collection $candles, int $kPeriod = 14, int $dPeriod = 3, int $smooth = 3): array
    {
        $data = $candles->toArray();
        $count = count($data);

        if ($count < $kPeriod + $dPeriod + $smooth) {
            return ['k' => 50, 'd' => 50, 'previous_k' => 50, 'previous_d' => 50, 'signal' => 'neutral', 'value' => 50];
        }

        // Rohe %K berechnen
        $rawK = [];
        for ($i = $kPeriod - 1; $i < $count; $i++) {
            $slice = array_slice($data, $i - $kPeriod + 1, $kPeriod);
            $highs = array_column($slice, 'high');
            $lows = array_column($slice, 'low');
            $highestHigh = max($highs);
            $lowestLow = min($lows);

            $range = $highestHigh - $lowestLow;
            $rawK[] = $range > 0 ? (($data[$i]['close'] - $lowestLow) / $range) * 100 : 50;
        }

        // %K glätten (SMA von rawK)
        $kValues = [];
        for ($i = $smooth - 1; $i < count($rawK); $i++) {
            $slice = array_slice($rawK, $i - $smooth + 1, $smooth);
            $kValues[] = array_sum($slice) / $smooth;
        }

        // %D = SMA von %K
        $dValues = [];
        for ($i = $dPeriod - 1; $i < count($kValues); $i++) {
            $slice = array_slice($kValues, $i - $dPeriod + 1, $dPeriod);
            $dValues[] = array_sum($slice) / $dPeriod;
        }

        $currentK = end($kValues);
        $currentD = end($dValues);
        $prevK = $kValues[count($kValues) - 2] ?? $currentK;
        $prevD = $dValues[count($dValues) - 2] ?? $currentD;

        $signal = 'neutral';
        if ($currentK < 20 && $currentD < 20) {
            $signal = 'bullish';
        } elseif ($currentK > 80 && $currentD > 80) {
            $signal = 'bearish';
        }

        return [
            'k' => round($currentK, 2),
            'd' => round($currentD, 2),
            'previous_k' => round($prevK, 2),
            'previous_d' => round($prevD, 2),
            'signal' => $signal,
            'value' => round($currentK, 2),
        ];
    }
}
