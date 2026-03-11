<?php

namespace App\Services\Strategies;

use App\DTOs\SignalDTO;
use Illuminate\Support\Collection;

class EMACrossover implements StrategyInterface
{
    public function getName(): string
    {
        return 'EMACrossover';
    }

    public function analyze(array $indicators, Collection $candles, array $higherTfIndicators): ?SignalDTO
    {
        $ema9 = $indicators['ema_9'] ?? null;
        $ema21 = $indicators['ema_21'] ?? null;
        $sma50 = $indicators['sma_50'] ?? null;
        $adx = $indicators['adx'] ?? null;
        $atr = $indicators['atr'] ?? null;

        if (! $ema9 || ! $ema21 || ! $sma50 || ! $adx || ! $atr) {
            return null;
        }

        $lastCandle = $candles->last();
        $close = $lastCandle['close'];
        $atrValue = $atr['value'];
        $adxValue = $adx['value'];

        // ADX muss > 20 sein (Trend vorhanden)
        if ($adxValue < 20) {
            return null;
        }

        $ema9Current = $ema9['value'];
        $ema9Prev = $ema9['previous'];
        $ema21Current = $ema21['value'];
        $ema21Prev = $ema21['previous'];

        // BUY: EMA9 kreuzt EMA21 von unten + Preis > SMA50
        if ($ema9Prev <= $ema21Prev && $ema9Current > $ema21Current && $close > $sma50['value']) {
            $sl = $close - ($atrValue * config('trading.risk.atr_sl_multiplier'));
            $slDistance = abs($close - $sl);
            $tp = $close + ($slDistance * config('trading.risk.min_rr_ratio'));

            return new SignalDTO(
                direction: 'BUY',
                confidence: min(0.85, 0.55 + ($adxValue / 100)),
                strategy: $this->getName(),
                entryPrice: $close,
                stopLoss: $sl,
                takeProfit: $tp,
                reasoning: "EMA9 kreuzt EMA21 bullish, Preis über SMA50, ADX: {$adxValue}",
            );
        }

        // SELL: EMA9 kreuzt EMA21 von oben + Preis < SMA50
        if ($ema9Prev >= $ema21Prev && $ema9Current < $ema21Current && $close < $sma50['value']) {
            $sl = $close + ($atrValue * config('trading.risk.atr_sl_multiplier'));
            $slDistance = abs($sl - $close);
            $tp = $close - ($slDistance * config('trading.risk.min_rr_ratio'));

            return new SignalDTO(
                direction: 'SELL',
                confidence: min(0.85, 0.55 + ($adxValue / 100)),
                strategy: $this->getName(),
                entryPrice: $close,
                stopLoss: $sl,
                takeProfit: $tp,
                reasoning: "EMA9 kreuzt EMA21 bearish, Preis unter SMA50, ADX: {$adxValue}",
            );
        }

        return null;
    }
}
