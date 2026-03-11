<?php

namespace App\Services\Strategies;

use App\DTOs\SignalDTO;
use Illuminate\Support\Collection;

class MACDCrossover implements StrategyInterface
{
    public function getName(): string
    {
        return 'MACDCrossover';
    }

    public function analyze(array $indicators, Collection $candles, array $higherTfIndicators): ?SignalDTO
    {
        $macd = $indicators['macd'] ?? null;
        $ema50 = $indicators['ema_50'] ?? null;
        $adx = $indicators['adx'] ?? null;
        $atr = $indicators['atr'] ?? null;

        if (! $macd || ! $ema50 || ! $adx || ! $atr) {
            return null;
        }

        $lastCandle = $candles->last();
        $close = $lastCandle['close'];
        $atrValue = $atr['value'];
        $adxValue = $adx['value'];

        // ADX muss mindestens 20 sein (Trend vorhanden)
        if ($adxValue < 20) {
            return null;
        }

        // MACD kreuzt Signal von unten (bullish crossover)
        $histogram = $macd['histogram'];
        $prevHistogram = $macd['previous_histogram'];

        // BUY: MACD kreuzt Signal von unten + Preis > EMA 50
        if ($histogram > 0 && $prevHistogram <= 0 && $close > $ema50['value']) {
            $sl = $close - ($atrValue * config('trading.risk.atr_sl_multiplier'));
            $slDistance = abs($close - $sl);
            $tp = $close + ($slDistance * config('trading.risk.min_rr_ratio'));

            return new SignalDTO(
                direction: 'BUY',
                confidence: min(0.9, 0.5 + ($adxValue / 100)),
                strategy: $this->getName(),
                entryPrice: $close,
                stopLoss: $sl,
                takeProfit: $tp,
                reasoning: "MACD bullish Crossover, Preis über EMA50 ({$ema50['value']}), ADX: {$adxValue}",
            );
        }

        // SELL: MACD kreuzt Signal von oben + Preis < EMA 50
        if ($histogram < 0 && $prevHistogram >= 0 && $close < $ema50['value']) {
            $sl = $close + ($atrValue * config('trading.risk.atr_sl_multiplier'));
            $slDistance = abs($sl - $close);
            $tp = $close - ($slDistance * config('trading.risk.min_rr_ratio'));

            return new SignalDTO(
                direction: 'SELL',
                confidence: min(0.9, 0.5 + ($adxValue / 100)),
                strategy: $this->getName(),
                entryPrice: $close,
                stopLoss: $sl,
                takeProfit: $tp,
                reasoning: "MACD bearish Crossover, Preis unter EMA50 ({$ema50['value']}), ADX: {$adxValue}",
            );
        }

        return null;
    }
}
