<?php

namespace App\Services\Strategies;

use App\DTOs\SignalDTO;
use Illuminate\Support\Collection;

class RSIReversal implements StrategyInterface
{
    public function getName(): string
    {
        return 'RSIReversal';
    }

    public function analyze(array $indicators, Collection $candles, array $higherTfIndicators): ?SignalDTO
    {
        $rsi = $indicators['rsi'] ?? null;
        $stoch = $indicators['stochastic'] ?? null;
        $atr = $indicators['atr'] ?? null;
        $h4Ema50 = $higherTfIndicators['ema_50'] ?? null;

        if (! $rsi || ! $stoch || ! $atr) {
            return null;
        }

        $lastCandle = $candles->last();
        $close = $lastCandle['close'];
        $open = $lastCandle['open'];
        $atrValue = $atr['value'];
        $rsiValue = $rsi['value'];
        $stochK = $stoch['k'];

        // BUY: RSI < 30 + bullish Candle + Stochastic %K < 20
        if ($rsiValue < 30 && $close > $open && $stochK < 20) {
            // H4-Trend Filter: Preis muss über H4 EMA50 sein
            if ($h4Ema50 && $close < $h4Ema50['value']) {
                return null;
            }

            $sl = $close - ($atrValue * config('trading.risk.atr_sl_multiplier'));
            $slDistance = abs($close - $sl);
            $tp = $close + ($slDistance * config('trading.risk.min_rr_ratio'));

            return new SignalDTO(
                direction: 'BUY',
                confidence: min(0.85, 0.6 + ((30 - $rsiValue) / 100)),
                strategy: $this->getName(),
                entryPrice: $close,
                stopLoss: $sl,
                takeProfit: $tp,
                reasoning: "RSI überverkauft ({$rsiValue}), bullish Candle, Stochastic: {$stochK}",
            );
        }

        // SELL: RSI > 70 + bearish Candle + Stochastic %K > 80
        if ($rsiValue > 70 && $close < $open && $stochK > 80) {
            // H4-Trend Filter: Preis muss unter H4 EMA50 sein
            if ($h4Ema50 && $close > $h4Ema50['value']) {
                return null;
            }

            $sl = $close + ($atrValue * config('trading.risk.atr_sl_multiplier'));
            $slDistance = abs($sl - $close);
            $tp = $close - ($slDistance * config('trading.risk.min_rr_ratio'));

            return new SignalDTO(
                direction: 'SELL',
                confidence: min(0.85, 0.6 + (($rsiValue - 70) / 100)),
                strategy: $this->getName(),
                entryPrice: $close,
                stopLoss: $sl,
                takeProfit: $tp,
                reasoning: "RSI überkauft ({$rsiValue}), bearish Candle, Stochastic: {$stochK}",
            );
        }

        return null;
    }
}
