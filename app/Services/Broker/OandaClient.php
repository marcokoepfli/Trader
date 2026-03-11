<?php

namespace App\Services\Broker;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OandaClient implements BrokerInterface
{
    private string $baseUrl;

    private string $token;

    private string $accountId;

    public function __construct()
    {
        $env = config('trading.oanda.environment', 'practice');
        $this->baseUrl = $env === 'live'
            ? 'https://api-fxtrade.oanda.com'
            : 'https://api-fxpractice.oanda.com';
        $this->token = config('trading.oanda.token');
        $this->accountId = config('trading.oanda.account_id');
    }

    public function getAccountInfo(): array
    {
        $response = $this->request('GET', "/v3/accounts/{$this->accountId}");

        return $response['account'] ?? [];
    }

    public function getCandles(string $pair, string $granularity, int $count): Collection
    {
        $response = $this->request('GET', "/v3/instruments/{$pair}/candles", [
            'granularity' => $granularity,
            'count' => $count,
            'price' => 'MBA', // Mid, Bid, Ask
        ]);

        $candles = collect($response['candles'] ?? [])
            ->filter(fn (array $c) => $c['complete'] ?? false)
            ->map(function (array $c) {
                $mid = $c['mid'];
                $time = $c['time'];
                $hour = (int) date('G', strtotime($time));

                return [
                    'time' => $time,
                    'open' => (float) $mid['o'],
                    'high' => (float) $mid['h'],
                    'low' => (float) $mid['l'],
                    'close' => (float) $mid['c'],
                    'volume' => (int) ($c['volume'] ?? 0),
                    'session' => $this->detectSession($hour),
                ];
            })
            ->values();

        return $candles;
    }

    public function placeMarketOrder(string $pair, int $units, float $sl, float $tp): array
    {
        $precision = $this->getPricePrecision($pair);

        $body = [
            'order' => [
                'type' => 'MARKET',
                'instrument' => $pair,
                'units' => (string) $units,
                'stopLossOnFill' => [
                    'price' => number_format($sl, $precision, '.', ''),
                ],
                'takeProfitOnFill' => [
                    'price' => number_format($tp, $precision, '.', ''),
                ],
                'timeInForce' => 'FOK',
                'positionFill' => 'DEFAULT',
            ],
        ];

        return $this->request('POST', "/v3/accounts/{$this->accountId}/orders", [], $body);
    }

    public function closeTrade(string $tradeId): array
    {
        return $this->request('PUT', "/v3/accounts/{$this->accountId}/trades/{$tradeId}/close");
    }

    /**
     * Teilposition schliessen (z.B. 50% bei Partial Take Profit)
     */
    public function partialCloseTrade(string $tradeId, int $units): array
    {
        return $this->request('PUT', "/v3/accounts/{$this->accountId}/trades/{$tradeId}/close", [], [
            'units' => (string) abs($units),
        ]);
    }

    public function getOpenTrades(): Collection
    {
        $response = $this->request('GET', "/v3/accounts/{$this->accountId}/openTrades");

        return collect($response['trades'] ?? []);
    }

    public function modifyTrade(string $tradeId, ?float $sl, ?float $tp): array
    {
        $body = [];

        if ($sl !== null) {
            $body['stopLoss'] = ['price' => number_format($sl, 5, '.', '')];
        }

        if ($tp !== null) {
            $body['takeProfit'] = ['price' => number_format($tp, 5, '.', '')];
        }

        return $this->request('PUT', "/v3/accounts/{$this->accountId}/trades/{$tradeId}/orders", [], $body);
    }

    public function getCurrentPrice(string $pair): array
    {
        $response = $this->request('GET', "/v3/accounts/{$this->accountId}/pricing", [
            'instruments' => $pair,
        ]);

        $price = $response['prices'][0] ?? [];

        return [
            'bid' => (float) ($price['bids'][0]['price'] ?? 0),
            'ask' => (float) ($price['asks'][0]['price'] ?? 0),
            'spread' => 0,
            'time' => $price['time'] ?? now()->toIso8601String(),
        ];
    }

    /** Bestimme die Trading-Session basierend auf UTC-Stunde */
    private function detectSession(int $utcHour): string
    {
        // Asien: 00:00 - 08:00 UTC
        if ($utcHour >= 0 && $utcHour < 8) {
            return 'asian';
        }

        // London: 08:00 - 12:00 UTC (vor NY-Overlap)
        if ($utcHour >= 8 && $utcHour < 13) {
            return 'london';
        }

        // Overlap London/NY: 13:00 - 17:00 UTC
        if ($utcHour >= 13 && $utcHour < 17) {
            return 'overlap';
        }

        // New York: 17:00 - 22:00 UTC
        if ($utcHour >= 17 && $utcHour < 22) {
            return 'newyork';
        }

        // Spät-Session
        return 'asian';
    }

    /** Preisgenauigkeit je nach Pair */
    private function getPricePrecision(string $pair): int
    {
        // JPY-Paare haben 3 Dezimalstellen, andere 5
        return str_contains($pair, 'JPY') ? 3 : 5;
    }

    /**
     * HTTP-Request mit Retry-Logik und Fehlerbehandlung
     */
    private function request(string $method, string $path, array $query = [], array $body = []): array
    {
        $log = Log::channel('trading');
        $url = $this->baseUrl.$path;
        $maxRetries = 3;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $request = Http::withHeaders([
                    'Authorization' => "Bearer {$this->token}",
                    'Content-Type' => 'application/json',
                    'Accept-Datetime-Format' => 'RFC3339',
                ])->timeout(30);

                $response = match (strtoupper($method)) {
                    'GET' => $request->get($url, $query),
                    'POST' => $request->post($url, $body),
                    'PUT' => $request->put($url, $body),
                    default => $request->get($url, $query),
                };

                // Rate Limit — warten und erneut versuchen
                if ($response->status() === 429) {
                    $waitSeconds = (int) ($response->header('Retry-After') ?: 2);
                    $log->warning("[OANDA] Rate Limit erreicht, warte {$waitSeconds}s (Versuch {$attempt}/{$maxRetries})");
                    sleep($waitSeconds);

                    continue;
                }

                if ($response->failed()) {
                    $log->error("[OANDA] HTTP {$response->status()} — {$method} {$path}", [
                        'response' => $response->body(),
                    ]);

                    if ($attempt < $maxRetries) {
                        sleep(pow(2, $attempt)); // Exponential Backoff

                        continue;
                    }

                    return ['error' => true, 'status' => $response->status(), 'message' => $response->body()];
                }

                return $response->json();

            } catch (\Exception $e) {
                $log->error("[OANDA] Netzwerkfehler — {$method} {$path} (Versuch {$attempt}/{$maxRetries})", [
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < $maxRetries) {
                    sleep(pow(2, $attempt));

                    continue;
                }

                return ['error' => true, 'message' => $e->getMessage()];
            }
        }

        return ['error' => true, 'message' => 'Max Retries erreicht'];
    }
}
