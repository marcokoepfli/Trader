<?php

namespace App\Services\Strategies;

use App\DTOs\SignalDTO;
use Illuminate\Support\Collection;

interface StrategyInterface
{
    public function getName(): string;

    /**
     * Markt analysieren und Signal generieren
     *
     * @param  array  $indicators  Indikatoren des Haupt-Timeframes
     * @param  Collection  $candles  Candlestick-Daten
     * @param  array  $higherTfIndicators  Indikatoren des höheren Timeframes
     */
    public function analyze(array $indicators, Collection $candles, array $higherTfIndicators): ?SignalDTO;
}
