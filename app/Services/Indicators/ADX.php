<?php

namespace App\Services\Indicators;

use Illuminate\Support\Collection;

class ADX
{
    /**
     * Average Directional Index berechnen
     *
     * @return array{value: float, plus_di: float, minus_di: float, previous: float, signal: string}
     */
    public function calculate(Collection $candles, int $period = 14): array
    {
        $data = $candles->toArray();
        $count = count($data);

        if ($count < $period * 2 + 1) {
            return ['value' => 0, 'plus_di' => 0, 'minus_di' => 0, 'previous' => 0, 'signal' => 'neutral'];
        }

        // +DM, -DM und TR berechnen
        $plusDM = [];
        $minusDM = [];
        $tr = [];

        for ($i = 1; $i < $count; $i++) {
            $highDiff = $data[$i]['high'] - $data[$i - 1]['high'];
            $lowDiff = $data[$i - 1]['low'] - $data[$i]['low'];

            $plusDM[] = ($highDiff > $lowDiff && $highDiff > 0) ? $highDiff : 0;
            $minusDM[] = ($lowDiff > $highDiff && $lowDiff > 0) ? $lowDiff : 0;

            $tr[] = max(
                $data[$i]['high'] - $data[$i]['low'],
                abs($data[$i]['high'] - $data[$i - 1]['close']),
                abs($data[$i]['low'] - $data[$i - 1]['close'])
            );
        }

        // Wilder's Smoothing für +DM, -DM und TR
        $smoothPlusDM = array_sum(array_slice($plusDM, 0, $period));
        $smoothMinusDM = array_sum(array_slice($minusDM, 0, $period));
        $smoothTR = array_sum(array_slice($tr, 0, $period));

        $dxValues = [];

        for ($i = $period; $i < count($tr); $i++) {
            $smoothPlusDM = $smoothPlusDM - ($smoothPlusDM / $period) + $plusDM[$i];
            $smoothMinusDM = $smoothMinusDM - ($smoothMinusDM / $period) + $minusDM[$i];
            $smoothTR = $smoothTR - ($smoothTR / $period) + $tr[$i];

            $plusDI = $smoothTR > 0 ? ($smoothPlusDM / $smoothTR) * 100 : 0;
            $minusDI = $smoothTR > 0 ? ($smoothMinusDM / $smoothTR) * 100 : 0;

            $diSum = $plusDI + $minusDI;
            $dx = $diSum > 0 ? (abs($plusDI - $minusDI) / $diSum) * 100 : 0;
            $dxValues[] = ['dx' => $dx, 'plus_di' => $plusDI, 'minus_di' => $minusDI];
        }

        if (count($dxValues) < $period) {
            return ['value' => 0, 'plus_di' => 0, 'minus_di' => 0, 'previous' => 0, 'signal' => 'neutral'];
        }

        // ADX = Smoothed DX
        $adx = 0;
        for ($i = 0; $i < $period; $i++) {
            $adx += $dxValues[$i]['dx'];
        }
        $adx /= $period;

        $adxValues = [$adx];
        for ($i = $period; $i < count($dxValues); $i++) {
            $adx = (($adx * ($period - 1)) + $dxValues[$i]['dx']) / $period;
            $adxValues[] = $adx;
        }

        $current = end($adxValues);
        $previous = $adxValues[count($adxValues) - 2] ?? $current;
        $lastDx = end($dxValues);

        // Signal: > 25 = trending, < 20 = ranging
        $signal = 'neutral';
        if ($current > 25) {
            $signal = 'trending';
        } elseif ($current < 20) {
            $signal = 'ranging';
        }

        return [
            'value' => round($current, 2),
            'plus_di' => round($lastDx['plus_di'], 2),
            'minus_di' => round($lastDx['minus_di'], 2),
            'previous' => round($previous, 2),
            'signal' => $signal,
        ];
    }
}
