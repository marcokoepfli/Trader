<?php

use App\Services\Indicators\ATR;
use App\Services\Indicators\BollingerBands;
use App\Services\Indicators\EMA;
use App\Services\Indicators\IndicatorService;
use App\Services\Indicators\MACD;
use App\Services\Indicators\RSI;
use App\Services\Indicators\SMA;
use App\Services\Indicators\Stochastic;
use Illuminate\Support\Collection;

function generateCandles(int $count = 100, float $startPrice = 1.1000): Collection
{
    $candles = [];
    $price = $startPrice;

    for ($i = 0; $i < $count; $i++) {
        $change = (rand(-100, 100) / 10000);
        $open = $price;
        $close = $price + $change;
        $high = max($open, $close) + abs($change) * rand(1, 3) / 10;
        $low = min($open, $close) - abs($change) * rand(1, 3) / 10;

        $candles[] = [
            'time' => now()->subHours($count - $i)->toIso8601String(),
            'open' => round($open, 5),
            'high' => round($high, 5),
            'low' => round($low, 5),
            'close' => round($close, 5),
            'volume' => rand(100, 5000),
            'session' => 'london',
        ];

        $price = $close;
    }

    return collect($candles);
}

test('SMA berechnet korrekt', function () {
    $candles = generateCandles(50);
    $sma = new SMA;
    $result = $sma->calculate($candles, 20);

    expect($result)->toHaveKeys(['value', 'previous', 'signal'])
        ->and($result['value'])->toBeGreaterThan(0)
        ->and($result['signal'])->toBeIn(['bullish', 'bearish', 'neutral']);
});

test('EMA berechnet korrekt', function () {
    $candles = generateCandles(50);
    $ema = new EMA;
    $result = $ema->calculate($candles, 21);

    expect($result)->toHaveKeys(['value', 'previous', 'signal', 'values'])
        ->and($result['value'])->toBeGreaterThan(0)
        ->and($result['values'])->toBeArray()->not->toBeEmpty();
});

test('RSI liefert Wert zwischen 0 und 100', function () {
    $candles = generateCandles(50);
    $rsi = new RSI;
    $result = $rsi->calculate($candles);

    expect($result['value'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100);
});

test('MACD berechnet alle Komponenten', function () {
    $candles = generateCandles(100);
    $macd = new MACD;
    $result = $macd->calculate($candles);

    expect($result)->toHaveKeys(['macd_line', 'signal_line', 'histogram', 'previous_histogram', 'signal']);
});

test('Bollinger Bands hat upper, middle, lower', function () {
    $candles = generateCandles(50);
    $bb = new BollingerBands;
    $result = $bb->calculate($candles);

    expect($result)->toHaveKeys(['upper', 'middle', 'lower', 'bandwidth', 'percent_b'])
        ->and($result['upper'])->toBeGreaterThan($result['middle'])
        ->and($result['middle'])->toBeGreaterThan($result['lower']);
});

test('ATR ist positiv', function () {
    $candles = generateCandles(50);
    $atr = new ATR;
    $result = $atr->calculate($candles);

    expect($result['value'])->toBeGreaterThan(0);
});

test('Stochastic liefert Werte zwischen 0 und 100', function () {
    $candles = generateCandles(50);
    $stoch = new Stochastic;
    $result = $stoch->calculate($candles);

    expect($result['k'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100)
        ->and($result['d'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100);
});

test('IndicatorService berechnet alle Indikatoren', function () {
    $candles = generateCandles(250);
    $service = new IndicatorService;
    $result = $service->calculateAll($candles);

    expect($result)->toHaveKeys([
        'sma_10', 'sma_20', 'sma_50', 'sma_200',
        'ema_9', 'ema_21', 'ema_50',
        'rsi', 'macd', 'bollinger', 'atr',
        'stochastic', 'adx', 'fibonacci',
        'market_condition',
    ]);
});

test('IndicatorService erkennt Marktbedingung', function () {
    $candles = generateCandles(250);
    $service = new IndicatorService;
    $result = $service->calculateAll($candles);

    expect($result['market_condition'])->toBeIn(['trending', 'ranging', 'volatile', 'quiet']);
});
