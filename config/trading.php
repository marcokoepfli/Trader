<?php

return [
    // Handelbare Währungspaare
    'pairs' => ['EUR_USD', 'GBP_USD', 'USD_JPY', 'AUD_USD', 'USD_CHF'],
    'timeframe' => 'H1',
    'higher_timeframe' => 'H4',

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
