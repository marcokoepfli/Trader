<?php

use App\Models\StrategyScore;
use App\Models\TradingRule;
use App\Services\Strategies\MACDCrossover;
use App\Services\Strategies\RSIReversal;
use App\Services\Strategies\StrategyAggregator;

test('Strategie-Klassen implementieren Interface korrekt', function () {
    $macd = new MACDCrossover;
    $rsi = new RSIReversal;

    expect($macd->getName())->toBe('MACDCrossover')
        ->and($rsi->getName())->toBe('RSIReversal');
});

test('StrategyScore isAvailable prüft Score', function () {
    $score = StrategyScore::factory()->create([
        'strategy' => 'TestStrategy',
        'score' => 0.2,
        'on_cooldown' => false,
    ]);

    expect($score->isAvailable())->toBeFalse();

    $score->update(['score' => 0.5]);
    expect($score->fresh()->isAvailable())->toBeTrue();
});

test('StrategyScore isAvailable prüft Cooldown', function () {
    $score = StrategyScore::factory()->create([
        'strategy' => 'CooldownTest',
        'score' => 0.8,
        'on_cooldown' => true,
        'cooldown_until' => now()->addHour(),
    ]);

    expect($score->isAvailable())->toBeFalse();
});

test('StrategyScore beendet abgelaufenen Cooldown', function () {
    $score = StrategyScore::factory()->create([
        'strategy' => 'ExpiredCooldown',
        'score' => 0.8,
        'on_cooldown' => true,
        'cooldown_until' => now()->subHour(),
    ]);

    expect($score->isAvailable())->toBeTrue()
        ->and($score->fresh()->on_cooldown)->toBeFalse();
});

test('TradingRule blockiert korrekt nach Session', function () {
    $rule = TradingRule::factory()->create([
        'type' => 'session_block',
        'conditions' => ['strategy' => 'MACDCrossover', 'session' => 'asian'],
        'active' => true,
    ]);

    expect($rule->blocks('MACDCrossover', 'EUR_USD', 'asian', []))->toBeTrue()
        ->and($rule->blocks('MACDCrossover', 'EUR_USD', 'london', []))->toBeFalse()
        ->and($rule->blocks('RSIReversal', 'EUR_USD', 'asian', []))->toBeFalse();
});

test('TradingRule blockiert korrekt nach Indikator', function () {
    $rule = TradingRule::factory()->create([
        'type' => 'indicator_filter',
        'conditions' => ['indicator' => 'adx', 'operator' => '<', 'value' => 15],
        'active' => true,
    ]);

    expect($rule->blocks('Test', 'EUR_USD', '', ['adx' => ['value' => 10]]))->toBeTrue()
        ->and($rule->blocks('Test', 'EUR_USD', '', ['adx' => ['value' => 25]]))->toBeFalse();
});

test('Inaktive Regel blockiert nicht', function () {
    $rule = TradingRule::factory()->create([
        'type' => 'session_block',
        'conditions' => ['strategy' => 'MACDCrossover', 'session' => 'asian'],
        'active' => false,
    ]);

    expect($rule->blocks('MACDCrossover', 'EUR_USD', 'asian', []))->toBeFalse();
});

test('StrategyAggregator kann instanziiert werden', function () {
    $aggregator = new StrategyAggregator;

    expect($aggregator)->toBeInstanceOf(StrategyAggregator::class);
});
