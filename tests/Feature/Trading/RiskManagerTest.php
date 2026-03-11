<?php

use App\DTOs\SignalDTO;
use App\Models\Trade;

test('SignalDTO berechnet Risk-Reward korrekt', function () {
    $signal = new SignalDTO(
        direction: 'BUY',
        confidence: 0.8,
        strategy: 'Test',
        entryPrice: 1.10000,
        stopLoss: 1.09500,
        takeProfit: 1.11000,
        reasoning: 'Test',
    );

    expect(round($signal->riskRewardRatio(), 1))->toBe(2.0);
});

test('SignalDTO toArray enthält alle Felder', function () {
    $signal = new SignalDTO(
        direction: 'SELL',
        confidence: 0.75,
        strategy: 'MACDCrossover',
        entryPrice: 1.10000,
        stopLoss: 1.10500,
        takeProfit: 1.09000,
        reasoning: 'Test reasoning',
    );

    $array = $signal->toArray();
    expect($array)->toHaveKeys(['direction', 'confidence', 'strategy', 'entry_price', 'stop_loss', 'take_profit', 'reasoning']);
});

test('Trade Factory erstellt gültige Trades', function () {
    $trade = Trade::factory()->create();

    expect($trade)->toBeInstanceOf(Trade::class)
        ->and($trade->result)->toBe('OPEN')
        ->and($trade->pair)->not->toBeEmpty();
});

test('Trade Factory kann Win-Trades erstellen', function () {
    $trade = Trade::factory()->win()->create();

    expect($trade->result)->toBe('WIN')
        ->and($trade->pnl)->toBeGreaterThan(0)
        ->and($trade->hit_take_profit)->toBeTrue();
});

test('Trade Factory kann Loss-Trades erstellen', function () {
    $trade = Trade::factory()->loss()->create();

    expect($trade->result)->toBe('LOSS')
        ->and($trade->pnl)->toBeLessThan(0)
        ->and($trade->hit_stop_loss)->toBeTrue();
});

test('Trade Scopes filtern korrekt', function () {
    Trade::factory()->count(3)->create();
    Trade::factory()->win()->count(2)->create();
    Trade::factory()->loss()->count(1)->create();

    expect(Trade::open()->count())->toBe(3)
        ->and(Trade::wins()->count())->toBe(2)
        ->and(Trade::losses()->count())->toBe(1)
        ->and(Trade::closed()->count())->toBe(3);
});
