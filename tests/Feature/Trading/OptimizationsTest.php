<?php

use App\DTOs\SignalDTO;
use App\Models\BotState;
use App\Models\StrategyScore;
use App\Models\Trade;
use App\Services\LearningEngine;
use App\Services\NewsFilter;
use App\Services\RiskManager;
use Illuminate\Support\Carbon;

test('Dynamische Positionsgrösse skaliert mit Confidence', function () {
    $broker = Mockery::mock(\App\Services\Broker\OandaClient::class);
    $riskManager = new RiskManager($broker);

    $balance = 10000;
    $entry = 1.10000;
    $sl = 1.09500;

    // Hohe Confidence → volle Positionsgrösse
    $highUnits = $riskManager->calculatePositionSize($balance, $entry, $sl, 0.9);

    // Niedrige Confidence → kleinere Position
    $lowUnits = $riskManager->calculatePositionSize($balance, $entry, $sl, 0.4);

    expect($highUnits)->toBeGreaterThan($lowUnits)
        ->and($highUnits)->toBeGreaterThan(0)
        ->and($lowUnits)->toBeGreaterThan(0);
});

test('Dynamische Positionsgrösse ohne Confidence nutzt vollen Risikobetrag', function () {
    $broker = Mockery::mock(\App\Services\Broker\OandaClient::class);
    $riskManager = new RiskManager($broker);

    $balance = 10000;
    $entry = 1.10000;
    $sl = 1.09500;

    // Default confidence = 1.0 → volle Positionsgrösse
    $units = $riskManager->calculatePositionSize($balance, $entry, $sl);

    expect($units)->toBeGreaterThan(0);
});

test('NewsFilter erkennt wiederkehrende Events', function () {
    $newsFilter = new NewsFilter;

    // Simuliere einen Freitag um 13:30 UTC (NFP-Zeit)
    Carbon::setTestNow(Carbon::create(2026, 3, 13, 13, 30, 0, 'UTC')); // Freitag

    $result = $newsFilter->isBlocked('EUR_USD');

    // EUR_USD enthält USD → sollte blockiert sein wegen NFP
    expect($result['blocked'])->toBeTrue()
        ->and($result['reason'])->toContain('Non-Farm Payrolls');

    Carbon::setTestNow(); // Reset
});

test('NewsFilter blockiert nicht ausserhalb Zeitfenster', function () {
    $newsFilter = new NewsFilter;

    // Simuliere einen Freitag um 10:00 UTC (weit weg von NFP 13:30)
    Carbon::setTestNow(Carbon::create(2026, 3, 13, 10, 0, 0, 'UTC'));

    $result = $newsFilter->isBlocked('EUR_USD');

    expect($result['blocked'])->toBeFalse();

    Carbon::setTestNow();
});

test('NewsFilter blockiert nicht bei unbetroffenem Pair', function () {
    $newsFilter = new NewsFilter;

    // NFP betrifft USD, nicht AUD/JPY-only
    Carbon::setTestNow(Carbon::create(2026, 3, 13, 13, 30, 0, 'UTC'));

    // AUD_JPY enthält weder USD noch EUR
    $result = $newsFilter->isBlocked('AUD_JPY');

    expect($result['blocked'])->toBeFalse();

    Carbon::setTestNow();
});

test('Trade hat partial_close_done Feld', function () {
    $trade = Trade::factory()->create([
        'partial_close_done' => false,
        'original_position_size' => 5000,
    ]);

    expect($trade->partial_close_done)->toBeFalse()
        ->and($trade->original_position_size)->toBe(5000);

    $trade->update(['partial_close_done' => true]);

    expect($trade->fresh()->partial_close_done)->toBeTrue();
});

test('LearningEngine optimizeAdaptiveParameters erstellt Adaptive Values', function () {
    // Strategie-Score erstellen
    StrategyScore::factory()->create(['strategy' => 'MACDCrossover']);

    // 15 Trades erstellen (10 Wins in London, 5 Losses in Asian)
    for ($i = 0; $i < 10; $i++) {
        Trade::factory()->win()->create([
            'strategy' => 'MACDCrossover',
            'session' => 'london',
            'market_condition' => 'trending',
        ]);
    }
    for ($i = 0; $i < 5; $i++) {
        Trade::factory()->loss()->create([
            'strategy' => 'MACDCrossover',
            'session' => 'asian',
            'market_condition' => 'ranging',
        ]);
    }

    $engine = new LearningEngine;
    $engine->optimizeAdaptiveParameters();

    // Sollte London als beste Session identifiziert haben
    $bestSession = BotState::getValue('best_session_MACDCrossover');
    expect($bestSession)->toBe('london');
});

test('Config enthält alle neuen Optimierungs-Einstellungen', function () {
    expect(config('trading.risk.dynamic_sizing'))->toBeTrue()
        ->and(config('trading.partial_tp.enabled'))->toBeTrue()
        ->and(config('trading.partial_tp.close_pct'))->toBe(0.5)
        ->and(config('trading.partial_tp.trigger_rr'))->toBe(1.0)
        ->and(config('trading.news_filter.enabled'))->toBeTrue()
        ->and(config('trading.entry_refinement.enabled'))->toBeTrue()
        ->and(config('trading.entry_refinement.timeframe'))->toBe('M15')
        ->and(config('trading.learning.adaptive_params'))->toBeTrue()
        ->and(config('trading.refinement_timeframe'))->toBe('M15');
});
