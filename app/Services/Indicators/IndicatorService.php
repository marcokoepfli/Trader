<?php

namespace App\Services\Indicators;

use Illuminate\Support\Collection;

class IndicatorService
{
    /**
     * Alle Indikatoren auf einmal berechnen
     *
     * @return array<string, array>
     */
    public function calculateAll(Collection $candles): array
    {
        $sma = new SMA;
        $ema = new EMA;
        $rsi = new RSI;
        $macd = new MACD;
        $bb = new BollingerBands;
        $atr = new ATR;
        $stoch = new Stochastic;
        $adx = new ADX;
        $fib = new FibonacciLevels;

        $atrResult = $atr->calculate($candles);
        $adxResult = $adx->calculate($candles);

        return [
            'sma_10' => $sma->calculate($candles, 10),
            'sma_20' => $sma->calculate($candles, 20),
            'sma_50' => $sma->calculate($candles, 50),
            'sma_200' => $sma->calculate($candles, 200),
            'ema_9' => $ema->calculate($candles, 9),
            'ema_21' => $ema->calculate($candles, 21),
            'ema_50' => $ema->calculate($candles, 50),
            'rsi' => $rsi->calculate($candles),
            'macd' => $macd->calculate($candles),
            'bollinger' => $bb->calculate($candles),
            'atr' => $atrResult,
            'stochastic' => $stoch->calculate($candles),
            'adx' => $adxResult,
            'fibonacci' => $fib->calculate($candles),
            'market_condition' => $this->detectMarketCondition($adxResult, $atrResult),
        ];
    }

    /**
     * Marktbedingung erkennen basierend auf ADX und ATR
     */
    private function detectMarketCondition(array $adx, array $atr): string
    {
        $adxValue = $adx['value'] ?? 0;
        $atrSignal = $atr['signal'] ?? 'neutral';

        if ($adxValue > 25 && $atrSignal === 'volatile') {
            return 'volatile';
        }

        if ($adxValue > 25) {
            return 'trending';
        }

        if ($adxValue < 20 && $atrSignal !== 'volatile') {
            return 'quiet';
        }

        return 'ranging';
    }
}
