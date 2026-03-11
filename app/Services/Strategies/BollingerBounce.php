<?php

namespace App\Services\Strategies;

use App\DTOs\SignalDTO;
use Illuminate\Support\Collection;

class BollingerBounce implements StrategyInterface
{
    public function getName(): string
    {
        return 'BollingerBounce';
    }

    public function analyze(array $indicators, Collection $candles, array $higherTfIndicators): ?SignalDTO
    {
        $bb = $indicators['bollinger'] ?? null;
        $rsi = $indicators['rsi'] ?? null;
        $adx = $indicators['adx'] ?? null;
        $atr = $indicators['atr'] ?? null;

        if (! $bb || ! $rsi || ! $adx || ! $atr) {
            return null;
        }

        $lastCandle = $candles->last();
        $close = $lastCandle['close'];
        $atrValue = $atr['value'];
        $adxValue = $adx['value'];
        $rsiValue = $rsi['value'];

        // Nur in Range-Markt (ADX < 25)
        if ($adxValue >= 25) {
            return null;
        }

        // BUY: Close bei/unter Lower Band + RSI < 40
        if ($close <= $bb['lower'] && $rsiValue < 40) {
            $sl = $close - ($atrValue * config('trading.risk.atr_sl_multiplier'));
            $slDistance = abs($close - $sl);
            $tp = $close + ($slDistance * config('trading.risk.min_rr_ratio'));

            return new SignalDTO(
                direction: 'BUY',
                confidence: min(0.8, 0.5 + ((40 - $rsiValue) / 100)),
                strategy: $this->getName(),
                entryPrice: $close,
                stopLoss: $sl,
                takeProfit: $tp,
                reasoning: "Preis am unteren Bollinger Band ({$bb['lower']}), RSI: {$rsiValue}, Range-Markt (ADX: {$adxValue})",
            );
        }

        // SELL: Close bei/über Upper Band + RSI > 60
        if ($close >= $bb['upper'] && $rsiValue > 60) {
            $sl = $close + ($atrValue * config('trading.risk.atr_sl_multiplier'));
            $slDistance = abs($sl - $close);
            $tp = $close - ($slDistance * config('trading.risk.min_rr_ratio'));

            return new SignalDTO(
                direction: 'SELL',
                confidence: min(0.8, 0.5 + (($rsiValue - 60) / 100)),
                strategy: $this->getName(),
                entryPrice: $close,
                stopLoss: $sl,
                takeProfit: $tp,
                reasoning: "Preis am oberen Bollinger Band ({$bb['upper']}), RSI: {$rsiValue}, Range-Markt (ADX: {$adxValue})",
            );
        }

        return null;
    }
}
