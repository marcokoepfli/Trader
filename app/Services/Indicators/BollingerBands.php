<?php

namespace App\Services\Indicators;

use Illuminate\Support\Collection;

class BollingerBands
{
    /**
     * Bollinger Bands berechnen (20, 2)
     *
     * @return array{upper: float, middle: float, lower: float, bandwidth: float, percent_b: float, signal: string, value: float}
     */
    public function calculate(Collection $candles, int $period = 20, float $stdDev = 2.0): array
    {
        $closes = $candles->pluck('close')->toArray();
        $count = count($closes);

        if ($count < $period) {
            return [
                'upper' => 0, 'middle' => 0, 'lower' => 0,
                'bandwidth' => 0, 'percent_b' => 0.5, 'signal' => 'neutral', 'value' => 0.5,
            ];
        }

        // SMA als Mittellinie
        $recentCloses = array_slice($closes, -$period);
        $middle = array_sum($recentCloses) / $period;

        // Standardabweichung
        $variance = 0;
        foreach ($recentCloses as $close) {
            $variance += pow($close - $middle, 2);
        }
        $sd = sqrt($variance / $period);

        $upper = $middle + ($stdDev * $sd);
        $lower = $middle - ($stdDev * $sd);

        // Bandwidth und %B
        $bandwidth = $middle > 0 ? ($upper - $lower) / $middle : 0;
        $lastClose = end($closes);
        $percentB = ($upper - $lower) > 0 ? ($lastClose - $lower) / ($upper - $lower) : 0.5;

        // Signal
        $signal = 'neutral';
        if ($lastClose <= $lower) {
            $signal = 'bullish'; // Preis am unteren Band = potenzielle Umkehr nach oben
        } elseif ($lastClose >= $upper) {
            $signal = 'bearish'; // Preis am oberen Band = potenzielle Umkehr nach unten
        }

        return [
            'upper' => round($upper, 6),
            'middle' => round($middle, 6),
            'lower' => round($lower, 6),
            'bandwidth' => round($bandwidth, 6),
            'percent_b' => round($percentB, 4),
            'signal' => $signal,
            'value' => round($percentB, 4),
        ];
    }
}
