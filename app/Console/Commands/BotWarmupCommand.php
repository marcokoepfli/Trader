<?php

namespace App\Console\Commands;

use App\DTOs\SignalDTO;
use App\Models\BotState;
use App\Models\StrategyScore;
use App\Models\Trade;
use App\Models\TradingRule;
use App\Services\Broker\OandaClient;
use App\Services\Indicators\IndicatorService;
use App\Services\LearningEngine;
use App\Services\NewsFilter;
use App\Services\Strategies\StrategyAggregator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BotWarmupCommand extends Command
{
    protected $signature = 'bot:warmup
        {--months=6 : Anzahl Monate historischer Daten}
        {--reset : Bestehende Scores/Regeln vorher löschen}';

    protected $description = 'Bot mit echten historischen Daten trainieren — exakt gleicher Code-Pfad wie Live-Trading';

    public function handle(
        OandaClient $broker,
        IndicatorService $indicatorService,
        StrategyAggregator $aggregator,
        LearningEngine $learningEngine,
        NewsFilter $newsFilter,
    ): int {
        $months = (int) $this->option('months');

        if ($this->option('reset')) {
            if ($this->confirm('Alle bestehenden Strategy Scores, Regeln, Warmup-Trades und adaptive Parameter löschen?')) {
                StrategyScore::query()->delete();
                TradingRule::query()->where('source', 'auto')->delete();
                Trade::query()->where('reasoning', 'LIKE', '%[warmup]%')->delete();

                // Alle adaptiven BotState-Keys löschen
                BotState::query()->where('key', 'LIKE', 'adaptive_%')->delete();
                BotState::query()->where('key', 'LIKE', 'best_session_%')->delete();
                BotState::query()->where('key', 'LIKE', 'avoid_condition_%')->delete();
                BotState::query()->whereIn('key', [
                    'peak_balance',
                    'is_paused',
                    'pause_until',
                    'pause_reason',
                ])->delete();

                $this->info('Bestehende Daten gelöscht.');
            }
        }

        $this->info("Bot Warmup: {$months} Monate echte OANDA-Daten laden und exakt wie Live durchlaufen...");
        $this->newLine();

        $pairs = config('trading.pairs');
        $timeframe = config('trading.timeframe');
        $higherTf = config('trading.higher_timeframe');
        $windowSize = config('trading.learning.lookback_bars', 200);

        // Balance für Warmup
        $account = $broker->getAccountInfo();
        $balance = (float) ($account['balance'] ?? 10000);
        $initialBalance = $balance;
        $peakBalance = $balance;
        BotState::setValue('peak_balance', (string) $balance);

        $totalStats = ['trades' => 0, 'wins' => 0, 'losses' => 0, 'pnl' => 0, 'skipped_news' => 0, 'skipped_session' => 0, 'skipped_h4' => 0, 'skipped_confluence' => 0];

        foreach ($pairs as $pair) {
            $this->info("═══ {$pair} ═══");

            // Echte historische Daten von OANDA laden
            $candleCount = min($months * 30 * 24, 5000);
            $h1Candles = $broker->getCandles($pair, $timeframe, $candleCount);
            $h4Candles = $broker->getCandles($pair, $higherTf, min($months * 30 * 6, 2000));
            $m15Candles = config('trading.entry_refinement.enabled')
                ? $broker->getCandles($pair, config('trading.entry_refinement.timeframe', 'M15'), min($candleCount * 4, 5000))
                : collect();

            if ($h1Candles->count() < $windowSize + 50) {
                $this->warn("  Nicht genug H1-Daten ({$h1Candles->count()}) — übersprungen");

                continue;
            }

            $this->info(sprintf(
                '  Geladen: %d H1, %d H4, %d M15 Candles',
                $h1Candles->count(),
                $h4Candles->count(),
                $m15Candles->count(),
            ));

            $bar = $this->output->createProgressBar($h1Candles->count() - $windowSize);
            $pairStats = ['trades' => 0, 'wins' => 0, 'losses' => 0, 'pnl' => 0];
            $skipUntilIndex = 0;

            for ($i = $windowSize; $i < $h1Candles->count(); $i++) {
                $bar->advance();

                // Trade läuft noch — überspringen
                if ($i < $skipUntilIndex) {
                    continue;
                }

                $window = $h1Candles->slice($i - $windowSize, $windowSize)->values();
                $lastCandle = $window->last();
                $candleTime = Carbon::parse($lastCandle['time'], 'UTC');
                $session = $lastCandle['session'] ?? 'unknown';

                // === EXAKT WIE LIVE: Session-Filter ===
                if (config('trading.session_filter.enabled')) {
                    $allowedSessions = config('trading.session_filter.allowed_sessions');
                    if (! in_array($session, $allowedSessions)) {
                        $totalStats['skipped_session']++;

                        continue;
                    }
                }

                // === EXAKT WIE LIVE: News-Filter mit historischem Zeitstempel ===
                if (config('trading.news_filter.enabled')) {
                    $newsCheck = $newsFilter->isBlocked($pair, $candleTime);
                    if ($newsCheck['blocked']) {
                        $totalStats['skipped_news']++;

                        continue;
                    }
                }

                // === EXAKT WIE LIVE: Indikatoren berechnen ===
                $indicators = $indicatorService->calculateAll($window);

                // H4-Daten zum passenden Zeitpunkt
                $h4Window = $this->getCandlesBefore($h4Candles, $candleTime, 100);
                $h4Indicators = $h4Window->count() >= 50
                    ? $indicatorService->calculateAll($h4Window)
                    : [];

                $marketCondition = $indicators['market_condition'] ?? 'ranging';

                // === EXAKT WIE LIVE: StrategyAggregator (Confluence + H4-Filter + Session-Filter + Widerspruchs-Check) ===
                $signal = $aggregator->analyze($indicators, $window, $h4Indicators);

                if (! $signal) {
                    $totalStats['skipped_confluence']++;

                    continue;
                }

                // === EXAKT WIE LIVE: M15 Entry Refinement ===
                if (config('trading.entry_refinement.enabled') && $m15Candles->isNotEmpty()) {
                    $signal = $this->refineEntryM15($m15Candles, $candleTime, $signal);
                    if (! $signal) {
                        continue;
                    }
                }

                // === EXAKT WIE LIVE: Positionsgrösse mit Confidence + Drawdown-Recovery ===
                $riskPct = config('trading.risk.max_per_trade');
                if (config('trading.risk.dynamic_sizing')) {
                    $minPct = config('trading.risk.min_confidence_risk_pct');
                    $maxPct = config('trading.risk.max_confidence_risk_pct');
                    $lowT = config('trading.risk.confidence_threshold_low');
                    $highT = config('trading.risk.confidence_threshold_high');
                    $norm = max(0, min(1, ($signal->confidence - $lowT) / ($highT - $lowT)));
                    $riskPct *= $minPct + ($maxPct - $minPct) * $norm;
                }

                // Drawdown-Recovery
                if (config('trading.risk.drawdown_recovery') && $peakBalance > 0 && $balance < $peakBalance) {
                    $drawdown = ($peakBalance - $balance) / $peakBalance;
                    if ($drawdown >= 0.05) {
                        $riskPct *= max(0.3, 1 - ($drawdown / 0.05) * 0.25);
                    }
                }

                $slDistance = abs($signal->entryPrice - $signal->stopLoss);
                if ($slDistance <= 0) {
                    continue;
                }

                $riskAmount = $balance * $riskPct;
                $units = (int) floor($riskAmount / $slDistance);
                if ($units <= 0) {
                    continue;
                }

                // === EXAKT WIE LIVE: Spread-bereinigtes R:R prüfen ===
                $pipSize = str_contains($pair, 'JPY') ? 0.01 : 0.0001;
                $avgSpreadPips = str_contains($pair, 'JPY') ? 1.5 : 1.2;
                $spreadCost = $avgSpreadPips * $pipSize;
                $risk = abs($signal->entryPrice - $signal->stopLoss);
                $reward = abs($signal->takeProfit - $signal->entryPrice) - $spreadCost;
                $adjustedRr = $risk > 0 ? $reward / $risk : 0;

                if ($adjustedRr < config('trading.risk.min_rr_ratio')) {
                    continue;
                }

                // === Trade mit echten zukünftigen Candles auflösen ===
                $tradeResult = $this->resolveTradeWithRealCandles(
                    $signal, $h1Candles, $i, $units, $spreadCost,
                );

                if (! $tradeResult) {
                    continue;
                }

                // Trade in DB speichern (exakt wie TradeExecutor)
                $trade = Trade::query()->create([
                    'pair' => $pair,
                    'direction' => $signal->direction,
                    'strategy' => $signal->strategy,
                    'entry_price' => $signal->entryPrice,
                    'exit_price' => $tradeResult['exit_price'],
                    'stop_loss' => $signal->stopLoss,
                    'take_profit' => $signal->takeProfit,
                    'position_size' => $tradeResult['final_units'],
                    'original_position_size' => $units,
                    'pnl' => $tradeResult['pnl'],
                    'pnl_pct' => $balance > 0 ? round(($tradeResult['pnl'] / $balance) * 100, 4) : 0,
                    'result' => $tradeResult['result'],
                    'confluence_score' => $signal->confidence,
                    'session' => $session,
                    'market_condition' => $marketCondition,
                    'indicators_at_entry' => $indicators,
                    'max_favorable' => $tradeResult['max_favorable'],
                    'max_adverse' => $tradeResult['max_adverse'],
                    'hit_stop_loss' => $tradeResult['result'] === 'LOSS',
                    'hit_take_profit' => $tradeResult['hit_tp'],
                    'partial_close_done' => $tradeResult['partial_done'],
                    'slippage' => 0,
                    'reasoning' => $signal->reasoning.' [warmup]',
                    'opened_at' => $candleTime,
                    'closed_at' => $tradeResult['closed_at'],
                ]);

                // === EXAKT WIE LIVE: LearningEngine nach jedem Trade ===
                $balance += $tradeResult['pnl'];
                if ($balance > $peakBalance) {
                    $peakBalance = $balance;
                    BotState::setValue('peak_balance', (string) $peakBalance);
                }

                $learningEngine->onTradeClosed($trade);

                // Stats
                $pairStats['trades']++;
                $pairStats['pnl'] += $tradeResult['pnl'];
                if ($tradeResult['result'] === 'WIN') {
                    $pairStats['wins']++;
                } else {
                    $pairStats['losses']++;
                }

                // Candles überspringen die der Trade verbraucht hat
                $skipUntilIndex = $i + $tradeResult['candles_used'];
            }

            $bar->finish();
            $this->newLine();

            $totalStats['trades'] += $pairStats['trades'];
            $totalStats['wins'] += $pairStats['wins'];
            $totalStats['losses'] += $pairStats['losses'];
            $totalStats['pnl'] += $pairStats['pnl'];

            $this->info(sprintf(
                '  → %d Trades (%dW/%dL), P&L: $%.2f',
                $pairStats['trades'],
                $pairStats['wins'],
                $pairStats['losses'],
                $pairStats['pnl'],
            ));
            $this->newLine();
        }

        if ($totalStats['trades'] === 0) {
            $this->warn('Keine Trades im historischen Zeitraum — Bot konnte nichts lernen.');

            return self::FAILURE;
        }

        // Finale Optimierung
        $this->info('Adaptive Parameter finalisieren...');
        $learningEngine->optimizeAdaptiveParameters();

        $this->displayResults($totalStats, $initialBalance, $balance);

        return self::SUCCESS;
    }

    /**
     * Trade mit echten nachfolgenden Candles auflösen
     * Inkl. Partial TP, Trailing Stop, Spread-Kosten — exakt wie ManageOpenTradesJob
     */
    private function resolveTradeWithRealCandles(
        SignalDTO $signal,
        Collection $candles,
        int $startIndex,
        int $units,
        float $spreadCost,
    ): ?array {
        $entry = $signal->entryPrice;
        $sl = $signal->stopLoss;
        $tp = $signal->takeProfit;
        $slDistance = abs($entry - $sl);

        // Partial TP Setup
        $partialEnabled = config('trading.partial_tp.enabled');
        $partialRR = config('trading.partial_tp.trigger_rr', 1.0);
        $partialPct = config('trading.partial_tp.close_pct', 0.5);
        $partialLevel = $signal->direction === 'BUY'
            ? $entry + ($slDistance * $partialRR)
            : $entry - ($slDistance * $partialRR);

        // Trailing Stop Setup
        $trailingEnabled = config('trading.risk.trailing_stop');
        $trailingMultiplier = (float) BotState::getValue('adaptive_trailing_multiplier', config('trading.risk.atr_trailing_multiplier'));

        $activeSl = $sl;
        $partialDone = false;
        $partialPnl = 0;
        $remainingUnits = $units;
        $maxFavorable = $entry;
        $maxAdverse = $entry;
        $bestPrice = $entry;

        for ($j = $startIndex + 1; $j < min($startIndex + 120, $candles->count()); $j++) {
            $candle = $candles[$j];

            if ($signal->direction === 'BUY') {
                $maxFavorable = max($maxFavorable, $candle['high']);
                $maxAdverse = min($maxAdverse, $candle['low']);
                $bestPrice = max($bestPrice, $candle['high']);

                // Partial TP
                if ($partialEnabled && ! $partialDone && $candle['high'] >= $partialLevel) {
                    $closeUnits = (int) floor($units * $partialPct);
                    $partialPnl = ($partialLevel - $entry - $spreadCost) * $closeUnits;
                    $remainingUnits -= $closeUnits;
                    $activeSl = $entry; // Breakeven
                    $partialDone = true;
                }

                // Trailing Stop
                if ($trailingEnabled && $candle['high'] > $entry) {
                    $atrApprox = $slDistance / config('trading.risk.atr_sl_multiplier', 1.5);
                    $trailDistance = $atrApprox * $trailingMultiplier;
                    $newSl = $candle['high'] - $trailDistance;
                    if ($newSl > $activeSl) {
                        $activeSl = $newSl;
                    }
                }

                // SL getroffen
                if ($candle['low'] <= $activeSl) {
                    $pnl = ($activeSl - $entry - $spreadCost) * $remainingUnits + $partialPnl;

                    return [
                        'pnl' => round($pnl, 2),
                        'result' => $pnl >= 0 ? 'WIN' : 'LOSS',
                        'exit_price' => $activeSl,
                        'max_favorable' => $maxFavorable,
                        'max_adverse' => $maxAdverse,
                        'partial_done' => $partialDone,
                        'hit_tp' => false,
                        'final_units' => $remainingUnits,
                        'candles_used' => $j - $startIndex,
                        'closed_at' => Carbon::parse($candle['time'], 'UTC'),
                    ];
                }

                // TP getroffen
                if ($candle['high'] >= $tp) {
                    $pnl = ($tp - $entry - $spreadCost) * $remainingUnits + $partialPnl;

                    return [
                        'pnl' => round($pnl, 2),
                        'result' => 'WIN',
                        'exit_price' => $tp,
                        'max_favorable' => $maxFavorable,
                        'max_adverse' => $maxAdverse,
                        'partial_done' => $partialDone,
                        'hit_tp' => true,
                        'final_units' => $remainingUnits,
                        'candles_used' => $j - $startIndex,
                        'closed_at' => Carbon::parse($candle['time'], 'UTC'),
                    ];
                }
            } else {
                // SELL
                $maxFavorable = min($maxFavorable, $candle['low']);
                $maxAdverse = max($maxAdverse, $candle['high']);
                $bestPrice = min($bestPrice, $candle['low']);

                if ($partialEnabled && ! $partialDone && $candle['low'] <= $partialLevel) {
                    $closeUnits = (int) floor($units * $partialPct);
                    $partialPnl = ($entry - $partialLevel - $spreadCost) * $closeUnits;
                    $remainingUnits -= $closeUnits;
                    $activeSl = $entry;
                    $partialDone = true;
                }

                if ($trailingEnabled && $candle['low'] < $entry) {
                    $atrApprox = $slDistance / config('trading.risk.atr_sl_multiplier', 1.5);
                    $trailDistance = $atrApprox * $trailingMultiplier;
                    $newSl = $candle['low'] + $trailDistance;
                    if ($newSl < $activeSl) {
                        $activeSl = $newSl;
                    }
                }

                if ($candle['high'] >= $activeSl) {
                    $pnl = ($entry - $activeSl - $spreadCost) * $remainingUnits + $partialPnl;

                    return [
                        'pnl' => round($pnl, 2),
                        'result' => $pnl >= 0 ? 'WIN' : 'LOSS',
                        'exit_price' => $activeSl,
                        'max_favorable' => $maxFavorable,
                        'max_adverse' => $maxAdverse,
                        'partial_done' => $partialDone,
                        'hit_tp' => false,
                        'final_units' => $remainingUnits,
                        'candles_used' => $j - $startIndex,
                        'closed_at' => Carbon::parse($candle['time'], 'UTC'),
                    ];
                }

                if ($candle['low'] <= $tp) {
                    $pnl = ($entry - $tp - $spreadCost) * $remainingUnits + $partialPnl;

                    return [
                        'pnl' => round($pnl, 2),
                        'result' => 'WIN',
                        'exit_price' => $tp,
                        'max_favorable' => $maxFavorable,
                        'max_adverse' => $maxAdverse,
                        'partial_done' => $partialDone,
                        'hit_tp' => true,
                        'final_units' => $remainingUnits,
                        'candles_used' => $j - $startIndex,
                        'closed_at' => Carbon::parse($candle['time'], 'UTC'),
                    ];
                }
            }
        }

        return null; // Trade lief aus (Timeout)
    }

    /**
     * M15 Entry Refinement mit historischen Daten — exakt wie AnalyzeMarketJob
     */
    private function refineEntryM15(Collection $m15Candles, Carbon $candleTime, SignalDTO $signal): ?SignalDTO
    {
        $m15Window = $this->getCandlesBefore($m15Candles, $candleTime, 20);

        if ($m15Window->count() < 10) {
            return $signal;
        }

        $lastCandle = $m15Window->last();

        if (config('trading.entry_refinement.require_confirmation')) {
            $isBullish = $lastCandle['close'] > $lastCandle['open'];
            $isBearish = $lastCandle['close'] < $lastCandle['open'];

            if ($signal->direction === 'BUY' && ! $isBullish) {
                return null;
            }
            if ($signal->direction === 'SELL' && ! $isBearish) {
                return null;
            }
        }

        // Besserer Entry von M15
        $refinedEntry = $lastCandle['close'];
        $recentLows = $m15Window->slice(-5)->pluck('low');
        $recentHighs = $m15Window->slice(-5)->pluck('high');

        if ($signal->direction === 'BUY') {
            $swingLow = $recentLows->min();
            $buffer = ($refinedEntry - $swingLow) * 0.1;
            $refinedSl = $swingLow - $buffer;

            if ($refinedSl > $signal->stopLoss && $refinedSl < $refinedEntry) {
                return new SignalDTO(
                    direction: $signal->direction,
                    confidence: $signal->confidence,
                    strategy: $signal->strategy,
                    entryPrice: $refinedEntry,
                    stopLoss: $refinedSl,
                    takeProfit: $signal->takeProfit,
                    reasoning: $signal->reasoning.' [M15 refined]',
                );
            }
        } else {
            $swingHigh = $recentHighs->max();
            $buffer = ($swingHigh - $refinedEntry) * 0.1;
            $refinedSl = $swingHigh + $buffer;

            if ($refinedSl < $signal->stopLoss && $refinedSl > $refinedEntry) {
                return new SignalDTO(
                    direction: $signal->direction,
                    confidence: $signal->confidence,
                    strategy: $signal->strategy,
                    entryPrice: $refinedEntry,
                    stopLoss: $refinedSl,
                    takeProfit: $signal->takeProfit,
                    reasoning: $signal->reasoning.' [M15 refined]',
                );
            }
        }

        return $signal;
    }

    /**
     * Candles vor einem bestimmten Zeitpunkt filtern
     */
    private function getCandlesBefore(Collection $candles, Carbon $before, int $count): Collection
    {
        if ($candles->isEmpty()) {
            return collect();
        }

        $beforeStr = $before->toIso8601String();

        return $candles
            ->filter(fn ($c) => ($c['time'] ?? '') <= $beforeStr)
            ->slice(-$count)
            ->values();
    }

    /**
     * Ergebnisse anzeigen
     */
    private function displayResults(array $stats, float $initialBalance, float $endBalance): void
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════════════');
        $this->info('  WARMUP ERGEBNISSE (echte historische Daten)');
        $this->info('═══════════════════════════════════════════════');

        $winRate = $stats['trades'] > 0 ? ($stats['wins'] / $stats['trades']) * 100 : 0;
        $returnPct = $initialBalance > 0 ? (($endBalance - $initialBalance) / $initialBalance) * 100 : 0;

        $this->table(
            ['Metrik', 'Wert'],
            [
                ['Echte Trades', $stats['trades']],
                ['Wins / Losses', "{$stats['wins']} / {$stats['losses']}"],
                ['Win Rate', sprintf('%.1f%%', $winRate)],
                ['Gesamt P&L', sprintf('$%.2f (%.1f%%)', $stats['pnl'], $returnPct)],
                ['End Balance', sprintf('$%.2f', $endBalance)],
                ['', ''],
                ['Gefiltert: Session', $stats['skipped_session']],
                ['Gefiltert: News', $stats['skipped_news']],
                ['Gefiltert: Keine Confluence', $stats['skipped_confluence']],
            ],
        );

        // Strategy Scores
        $this->newLine();
        $this->info('Gelernte Strategy Scores:');
        $scores = StrategyScore::all();
        if ($scores->isNotEmpty()) {
            $this->table(
                ['Strategie', 'Score', 'Win Rate', 'Trades', 'PF', 'Status'],
                $scores->map(fn ($s) => [
                    $s->strategy,
                    sprintf('%.2f', $s->score),
                    sprintf('%.0f%%', $s->win_rate),
                    $s->total_trades,
                    sprintf('%.2f', $s->profit_factor),
                    $s->on_cooldown ? 'COOLDOWN' : 'aktiv',
                ])->toArray(),
            );
        }

        // Auto-Regeln
        $rules = TradingRule::query()->where('source', 'auto')->get();
        if ($rules->isNotEmpty()) {
            $this->newLine();
            $this->info(sprintf('Automatisch erstellte Regeln (%d):', $rules->count()));
            foreach ($rules as $rule) {
                $status = $rule->active ? '✓' : '✗';
                $this->line("  {$status} {$rule->name}");
                $this->line("    {$rule->reason}");
            }
        }

        // Adaptive Parameter
        $this->newLine();
        $this->info('Adaptive Parameter:');
        $hasAdaptive = false;

        $strategyNames = ['MACDCrossover', 'RSIReversal', 'BollingerBounce', 'EMACrossover', 'BreakoutStrategy', 'FibonacciPullback'];
        foreach ($strategyNames as $name) {
            $slMult = BotState::getValue("adaptive_sl_multiplier_{$name}");
            $bestSession = BotState::getValue("best_session_{$name}");
            $avoidCondition = BotState::getValue("avoid_condition_{$name}");

            if ($slMult || $bestSession || $avoidCondition) {
                $hasAdaptive = true;
                $this->line("  {$name}:");
                if ($slMult) {
                    $this->line("    SL-Multiplier: {$slMult}x ATR");
                }
                if ($bestSession) {
                    $this->line("    Bevorzugte Session: {$bestSession}");
                }
                if ($avoidCondition) {
                    $this->line("    Vermeidet: {$avoidCondition}");
                }
            }
        }

        if (! $hasAdaptive) {
            $this->line('  Keine adaptiven Anpassungen nötig (alle Strategien gleichmässig)');
        }

        $this->newLine();
        $this->info('Warmup abgeschlossen — Bot startet mit Vorwissen aus echten Daten.');
    }
}
