<?php

namespace App\Services\Strategies;

use App\DTOs\SignalDTO;
use Illuminate\Support\Collection;

class FibonacciPullback implements StrategyInterface
{
    public function getName(): string
    {
        return 'FibonacciPullback';
    }

    public function analyze(array $indicators, Collection $candles, array $higherTfIndicators): ?SignalDTO
    {
        $fib = $indicators['fibonacci'] ?? null;
        $atr = $indicators['atr'] ?? null;
        $h4Ema50 = $higherTfIndicators['ema_50'] ?? null;

        if (! $fib || ! $atr || empty($fib['levels'])) {
            return null;
        }

        $lastCandle = $candles->last();
        $close = $lastCandle['close'];
        $open = $lastCandle['open'];
        $atrValue = $atr['value'];
        $fib618 = $fib['levels'][0.618] ?? null;

        if (! $fib618) {
            return null;
        }

        $range = $fib['swing_high'] - $fib['swing_low'];
        $tolerance = $range * 0.02;

        // Prüfe ob Preis nahe am 61.8% Level
        if (abs($close - $fib618) > $tolerance) {
            return null;
        }

        // BUY: Pullback zum 61.8% Level + bullish Candle + H4 Aufwärtstrend
        if ($close > $open && $fib['trend'] === 'bullish') {
            // H4 muss Aufwärtstrend bestätigen
            if ($h4Ema50 && $close < $h4Ema50['value']) {
                return null;
            }

            $sl = $close - ($atrValue * config('trading.risk.atr_sl_multiplier'));
            $slDistance = abs($close - $sl);
            $tp = $close + ($slDistance * config('trading.risk.min_rr_ratio'));

            return new SignalDTO(
                direction: 'BUY',
                confidence: 0.7,
                strategy: $this->getName(),
                entryPrice: $close,
                stopLoss: $sl,
                takeProfit: $tp,
                reasoning: "Fibonacci 61.8% Pullback ({$fib618}), bullish Candle, Aufwärtstrend bestätigt",
            );
        }

        // SELL: Pullback zum 61.8% Level + bearish Candle + H4 Abwärtstrend
        if ($close < $open && $fib['trend'] === 'bearish') {
            if ($h4Ema50 && $close > $h4Ema50['value']) {
                return null;
            }

            $sl = $close + ($atrValue * config('trading.risk.atr_sl_multiplier'));
            $slDistance = abs($sl - $close);
            $tp = $close - ($slDistance * config('trading.risk.min_rr_ratio'));

            return new SignalDTO(
                direction: 'SELL',
                confidence: 0.7,
                strategy: $this->getName(),
                entryPrice: $close,
                stopLoss: $sl,
                takeProfit: $tp,
                reasoning: "Fibonacci 61.8% Pullback ({$fib618}), bearish Candle, Abwärtstrend bestätigt",
            );
        }

        return null;
    }
}
