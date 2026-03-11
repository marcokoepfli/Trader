<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NewsFilter
{
    /**
     * Wiederkehrende High-Impact Events (UTC-Zeiten)
     * Format: [Wochentag => [Stunde => [betroffene Währungen]]]
     */
    private const RECURRING_EVENTS = [
        // NFP — Erster Freitag im Monat, 13:30 UTC
        'nfp' => ['day' => 5, 'hour' => 13, 'minute' => 30, 'currencies' => ['USD'], 'name' => 'Non-Farm Payrolls'],
        // FOMC — Mittwoch, 19:00 UTC (8x pro Jahr, wir blocken jeden Mi abends sicherheitshalber)
        'fomc' => ['day' => 3, 'hour' => 19, 'minute' => 0, 'currencies' => ['USD'], 'name' => 'FOMC Decision'],
        // EZB — Donnerstag, 13:15 UTC
        'ecb' => ['day' => 4, 'hour' => 13, 'minute' => 15, 'currencies' => ['EUR'], 'name' => 'ECB Rate Decision'],
        // BOE — Donnerstag, 12:00 UTC
        'boe' => ['day' => 4, 'hour' => 12, 'minute' => 0, 'currencies' => ['GBP'], 'name' => 'BOE Rate Decision'],
        // BOJ — Meist Freitag, 03:00 UTC
        'boj' => ['day' => 5, 'hour' => 3, 'minute' => 0, 'currencies' => ['JPY'], 'name' => 'BOJ Rate Decision'],
        // RBA — Dienstag, 03:30 UTC
        'rba' => ['day' => 2, 'hour' => 3, 'minute' => 30, 'currencies' => ['AUD'], 'name' => 'RBA Rate Decision'],
    ];

    /**
     * Blockier-Fenster in Minuten vor und nach einem Event
     */
    private const BLOCK_BEFORE_MINUTES = 30;

    private const BLOCK_AFTER_MINUTES = 30;

    /**
     * Prüfe ob ein Pair aktuell von News betroffen ist
     *
     * @return array{blocked: bool, reason: string|null}
     */
    public function isBlocked(string $pair, ?Carbon $at = null): array
    {
        $now = $at ?? Carbon::now('UTC');

        // Externe News-Daten prüfen (gecached) — nur im Live-Modus
        if (! $at) {
            $externalBlock = $this->checkExternalNews($pair, $now);
            if ($externalBlock['blocked']) {
                return $externalBlock;
            }
        }

        // Statische wiederkehrende Events prüfen
        return $this->checkRecurringEvents($pair, $now);
    }

    /**
     * Prüfe alle Pairs und gib blockierte zurück
     *
     * @return array<string, string> [pair => reason]
     */
    public function getBlockedPairs(array $pairs): array
    {
        $blocked = [];

        foreach ($pairs as $pair) {
            $check = $this->isBlocked($pair);
            if ($check['blocked']) {
                $blocked[$pair] = $check['reason'];
            }
        }

        return $blocked;
    }

    /**
     * Wiederkehrende Events prüfen
     */
    private function checkRecurringEvents(string $pair, Carbon $now): array
    {
        $pairCurrencies = $this->extractCurrencies($pair);

        foreach (self::RECURRING_EVENTS as $event) {
            // Wochentag stimmt?
            if ($now->dayOfWeekIso !== $event['day']) {
                continue;
            }

            // Währung betroffen?
            $affected = array_intersect($pairCurrencies, $event['currencies']);
            if (empty($affected)) {
                continue;
            }

            // Zeitfenster prüfen
            $eventTime = $now->copy()->setTime($event['hour'], $event['minute'], 0);
            $blockStart = $eventTime->copy()->subMinutes(self::BLOCK_BEFORE_MINUTES);
            $blockEnd = $eventTime->copy()->addMinutes(self::BLOCK_AFTER_MINUTES);

            if ($now->between($blockStart, $blockEnd)) {
                $reason = sprintf(
                    'News-Filter: %s (%s UTC) — Blockiert %d Min vor/nach Event',
                    $event['name'],
                    $eventTime->format('H:i'),
                    self::BLOCK_BEFORE_MINUTES,
                );

                Log::channel('trading')->info("[NEWS] {$pair} blockiert — {$event['name']}");

                return ['blocked' => true, 'reason' => $reason];
            }
        }

        return ['blocked' => false, 'reason' => null];
    }

    /**
     * Externe News-Daten aus Cache prüfen
     * Wird stündlich aktualisiert via fetchExternalNews()
     */
    private function checkExternalNews(string $pair, Carbon $now): array
    {
        $events = Cache::get('forex_news_events', []);
        $pairCurrencies = $this->extractCurrencies($pair);

        foreach ($events as $event) {
            $eventTime = Carbon::parse($event['time'], 'UTC');
            $blockStart = $eventTime->copy()->subMinutes(self::BLOCK_BEFORE_MINUTES);
            $blockEnd = $eventTime->copy()->addMinutes(self::BLOCK_AFTER_MINUTES);

            if (! $now->between($blockStart, $blockEnd)) {
                continue;
            }

            // Nur High-Impact Events
            if (($event['impact'] ?? '') !== 'high') {
                continue;
            }

            $eventCurrency = $event['currency'] ?? '';
            if (in_array($eventCurrency, $pairCurrencies)) {
                return [
                    'blocked' => true,
                    'reason' => sprintf('News: %s (%s, %s)', $event['title'] ?? 'Unknown', $eventCurrency, $eventTime->format('H:i')),
                ];
            }
        }

        return ['blocked' => false, 'reason' => null];
    }

    /**
     * Externe News-Daten abrufen und cachen (stündlich via Scheduler aufrufen)
     */
    public function fetchExternalNews(): void
    {
        try {
            // ForexFactory / Forex News API (kostenlos)
            $response = Http::timeout(10)->get('https://nfs.faireconomy.media/ff_calendar_thisweek.json');

            if ($response->failed()) {
                return;
            }

            $events = collect($response->json())
                ->filter(fn (array $event) => ($event['impact'] ?? '') === 'High')
                ->map(fn (array $event) => [
                    'title' => $event['title'] ?? '',
                    'currency' => strtoupper($event['country'] ?? ''),
                    'time' => $event['date'] ?? '',
                    'impact' => 'high',
                ])
                ->values()
                ->toArray();

            Cache::put('forex_news_events', $events, now()->addHours(2));

            Log::channel('trading')->debug('[NEWS] '.count($events).' High-Impact Events geladen');
        } catch (\Exception $e) {
            Log::channel('trading')->warning('[NEWS] Externe News konnten nicht geladen werden: '.$e->getMessage());
        }
    }

    /**
     * Währungen aus Pair extrahieren
     *
     * @return string[]
     */
    private function extractCurrencies(string $pair): array
    {
        $parts = explode('_', $pair);
        if (count($parts) === 2) {
            return $parts;
        }

        // Fallback für Format ohne Underscore
        return [substr($pair, 0, 3), substr($pair, 3, 3)];
    }
}
