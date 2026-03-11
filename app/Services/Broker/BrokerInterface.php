<?php

namespace App\Services\Broker;

use Illuminate\Support\Collection;

interface BrokerInterface
{
    /** Kontoinformationen abrufen */
    public function getAccountInfo(): array;

    /** Candlestick-Daten holen */
    public function getCandles(string $pair, string $granularity, int $count): Collection;

    /** Market Order platzieren */
    public function placeMarketOrder(string $pair, int $units, float $sl, float $tp): array;

    /** Trade schliessen */
    public function closeTrade(string $tradeId): array;

    /** Offene Trades abrufen */
    public function getOpenTrades(): Collection;

    /** Trade modifizieren (SL/TP anpassen) */
    public function modifyTrade(string $tradeId, ?float $sl, ?float $tp): array;

    /** Aktuellen Preis abrufen */
    public function getCurrentPrice(string $pair): array;
}
