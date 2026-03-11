<?php

namespace App\Services\Indicators;

use Illuminate\Support\Collection;

class MACD
{
    /**
     * MACD berechnen (12, 26, 9)
     *
     * @return array{macd_line: float, signal_line: float, histogram: float, previous_histogram: float, signal: string}
     */
    public function calculate(Collection $candles, int $fast = 12, int $slow = 26, int $signalPeriod = 9): array
    {
        $ema = new EMA;
        $closes = $candles->pluck('close')->toArray();
        $count = count($closes);

        if ($count < $slow + $signalPeriod) {
            return [
                'macd_line' => 0, 'signal_line' => 0, 'histogram' => 0,
                'previous_histogram' => 0, 'signal' => 'neutral', 'value' => 0,
            ];
        }

        // EMA-Werte berechnen
        $fastEma = $ema->calculate($candles, $fast)['values'];
        $slowEma = $ema->calculate($candles, $slow)['values'];

        // MACD-Linie = Fast EMA - Slow EMA (nur überlappende Werte)
        $offset = count($fastEma) - count($slowEma);
        $macdLine = [];
        for ($i = 0; $i < count($slowEma); $i++) {
            $macdLine[] = $fastEma[$i + $offset] - $slowEma[$i];
        }

        if (count($macdLine) < $signalPeriod) {
            return [
                'macd_line' => 0, 'signal_line' => 0, 'histogram' => 0,
                'previous_histogram' => 0, 'signal' => 'neutral', 'value' => 0,
            ];
        }

        // Signal-Linie = EMA der MACD-Linie
        $multiplier = 2.0 / ($signalPeriod + 1);
        $signalSma = array_sum(array_slice($macdLine, 0, $signalPeriod)) / $signalPeriod;
        $signalLine = [$signalSma];

        for ($i = $signalPeriod; $i < count($macdLine); $i++) {
            $val = ($macdLine[$i] - end($signalLine)) * $multiplier + end($signalLine);
            $signalLine[] = $val;
        }

        // Histogram
        $macdOffset = count($macdLine) - count($signalLine);
        $histograms = [];
        for ($i = 0; $i < count($signalLine); $i++) {
            $histograms[] = $macdLine[$i + $macdOffset] - $signalLine[$i];
        }

        $currentMacd = end($macdLine);
        $currentSignal = end($signalLine);
        $currentHist = end($histograms);
        $prevHist = $histograms[count($histograms) - 2] ?? 0;

        // Signal: Histogram dreht positiv = bullish
        $signal = 'neutral';
        if ($currentHist > 0 && $prevHist <= 0) {
            $signal = 'bullish';
        } elseif ($currentHist < 0 && $prevHist >= 0) {
            $signal = 'bearish';
        } elseif ($currentHist > 0) {
            $signal = 'bullish';
        } elseif ($currentHist < 0) {
            $signal = 'bearish';
        }

        return [
            'macd_line' => round($currentMacd, 6),
            'signal_line' => round($currentSignal, 6),
            'histogram' => round($currentHist, 6),
            'previous_histogram' => round($prevHist, 6),
            'signal' => $signal,
            'value' => round($currentHist, 6),
        ];
    }
}
