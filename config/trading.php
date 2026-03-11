<?php

return [
    // Handelbare Währungspaare
    'pairs' => ['EUR_USD', 'GBP_USD', 'USD_JPY', 'AUD_USD', 'USD_CHF'],
    'timeframe' => 'H1',
    'higher_timeframe' => 'H4',
    'refinement_timeframe' => 'M15',

    // Risikomanagement
    'risk' => [
        'max_per_trade' => (float) env('MAX_RISK_PER_TRADE', 0.02),
        'max_open_trades' => 3,
        'max_daily_loss' => 0.05,
        'max_drawdown' => 0.20,
        'min_rr_ratio' => 1.5,
        'trailing_stop' => true,
        'atr_sl_multiplier' => 1.5,
        'atr_trailing_multiplier' => 1.0,

        // Dynamische Positionsgrösse basierend auf Confidence
        'dynamic_sizing' => true,
        'min_confidence_risk_pct' => 0.5, // 50% des Risikos bei niedrigster Confidence
        'max_confidence_risk_pct' => 1.0, // 100% des Risikos bei höchster Confidence
        'confidence_threshold_low' => 0.5,
        'confidence_threshold_high' => 0.85,
    ],

    // Partial Take Profit
    'partial_tp' => [
        'enabled' => true,
        'close_pct' => 0.5, // 50% der Position bei 1:1 R:R schliessen
        'trigger_rr' => 1.0, // Bei 1:1 R:R auslösen
        'move_sl_to_breakeven' => true, // SL auf Einstand nach Partial Close
    ],

    // News-Filter
    'news_filter' => [
        'enabled' => true,
        'block_before_minutes' => 30,
        'block_after_minutes' => 30,
    ],

    // M15 Entry Refinement
    'entry_refinement' => [
        'enabled' => true,
        'timeframe' => 'M15',
        'max_wait_candles' => 4, // Max 4 M15-Kerzen warten (1h)
        'require_confirmation' => true, // M15-Kerze muss Richtung bestätigen
    ],

    // Strategie-Einstellungen
    'strategy' => [
        'min_confluence' => 2,
        'cooldown_after_losses' => 3,
        'min_score_to_trade' => 0.3,
    ],

    // Lern-Engine
    'learning' => [
        'min_trades' => 10,
        'lookback_bars' => 200,

        // Adaptive Parameter-Optimierung
        'adaptive_params' => true,
        'optimization_interval_trades' => 20, // Nach je 20 Trades optimieren
    ],

    // Bot-Einstellungen
    'bot' => [
        'analysis_interval' => 5,
        'max_spread_pips' => 3.0,
    ],

    // Korrelierte Paare (gleichzeitig nur eines davon handeln)
    'correlated_pairs' => [
        ['EUR_USD', 'GBP_USD'],
        ['USD_CHF', 'EUR_USD'],
        ['AUD_USD', 'EUR_USD'],
    ],

    // OANDA API-Konfiguration
    'oanda' => [
        'token' => env('OANDA_API_TOKEN'),
        'account_id' => env('OANDA_ACCOUNT_ID'),
        'environment' => env('OANDA_ENVIRONMENT', 'practice'),
    ],
];
