<?php

namespace App\Console\Commands;

use App\DTOs\SignalDTO;
use App\Services\Broker\OandaClient;
use App\Services\Indicators\IndicatorService;
use App\Services\Strategies\BollingerBounce;
use App\Services\Strategies\BreakoutStrategy;
use App\Services\Strategies\EMACrossover;
use App\Services\Strategies\FibonacciPullback;
use App\Services\Strategies\MACDCrossover;
use App\Services\Strategies\RSIReversal;
use App\Services\Strategies\StrategyInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class BacktestCommand extends Command
{
    protected $signature = 'bot:backtest
        {strategy? : Einzelstrategie oder "all" für Confluence}
        {pair? : Währungspaar oder "all" für alle Pairs}
        {--months=6 : Anzahl Monate zurück}
        {--confluence : Confluence-Modus (alle Strategien zusammen)}';

    protected $description = 'Strategie backtesten mit historischen Daten (einzeln oder Confluence)';

    /** @var StrategyInterface[] */
    private array $allStrategies;

    private IndicatorService $indicatorService;

    public function handle(OandaClient $broker, IndicatorService $indicatorService): int
    {
        $this->indicatorService = $indicatorService;
        $this->allStrategies = [
            new MACDCrossover,
            new RSIReversal,
            new BollingerBounce,
            new EMACrossover,
            new BreakoutStrategy,
            new FibonacciPullback,
        ];

        $strategyName = $this->argument('strategy') ?? 'all';
        $pairArg = $this->argument('pair') ?? 'all';
        $months = (int) $this->option('months');
        $useConfluence = $this->option('confluence') || $strategyName === 'all';

        $pairs = $pairArg === 'all'
            ? config('trading.pairs')
            : [$pairArg];

        if ($useConfluence) {
            $this->info('Confluence-Backtest auf '.implode(', ', $pairs)." ({$months} Monate)");
        } else {
            $strategy = $this->resolveStrategy($strategyName);
            if (! $strategy) {
                $this->error("Strategie '{$strategyName}' nicht gefunden.");
                $this->info('Verfügbar: MACDCrossover, RSIReversal, BollingerBounce, EMACrossover, BreakoutStrategy, FibonacciPullback, all');

                return self::FAILURE;
            }
            $this->info("Backtest: {$strategyName} auf ".implode(', ', $pairs)." ({$months} Monate)");
        }

        $this->newLine();

        $totalResults = [
            'trades' => 0, 'wins' => 0, 'losses' => 0,
            'winPnl' => 0, 'lossPnl' => 0, 'totalPnl' => 0,
            'maxDrawdown' => 0, 'returns' => [],
        ];

        $balance = 10000.0;
        $initialBalance = $balance;
        $peakBalance = $balance;

        foreach ($pairs as $pair) {
            $this->info("→ {$pair}...");

            $candleCount = min($months * 30 * 24, 5000);
            $candles = $broker->getCandles($pair, config('trading.timeframe'), $candleCount);
            $h4Candles = $broker->getCandles($pair, config('trading.higher_timeframe'), min($months * 30 * 6, 2000));

            if ($candles->count() < 200) {
                $this->warn("  Nicht genug Daten ({$candles->count()} Candles)");

                continue;
            }

            $this->info("  {$candles->count()} H1 + {$h4Candles->count()} H4 Candles geladen");

            $windowSize = 200;
            $bar = $this->output->createProgressBar($candles->count() - $windowSize);

            for ($i = $windowSize; $i < $candles->count(); $i++) {
                $bar->advance();

                $window = $candles->slice($i - $windowSize, $windowSize)->values();
                $indicators = $indicatorService->calculateAll($window);

                // H4 Indikatoren
                $h4Window = $this->getH4Window($h4Candles, $window->last()['time'] ?? '', 100);
                $h4Indicators = $h4Window->count() >= 50
                    ? $indicatorService->calculateAll($h4Window)
                    : [];

                // Session-Filter
                $session = $window->last()['session'] ?? 'unknown';
                if (config('trading.session_filter.enabled')) {
                    $allowedSessions = config('trading.session_filter.allowed_sessions');
                    if (! in_array($session, $allowedSessions)) {
                        continue;
                    }
                }

                // Signal generieren
                $signal = $useConfluence
                    ? $this->getConfluenceSignal($indicators, $window, $h4Indicators)
                    : $strategy->analyze($indicators, $window, $h4Indicators);

                if (! $signal) {
                    continue;
                }

                // H4-Trend Filter
                if (config('trading.h4_trend_filter.enabled') && ! empty($h4Indicators)) {
                    if (! $this->checkH4Trend($signal->direction, $h4Indicators)) {
                        continue;
                    }
                }

                // Trade simulieren
                $result = $this->simulateTrade($signal, $candles, $i, $balance);
                if (! $result) {
                    continue;
                }

                // Partial TP simulieren
                $pnl = $this->simulateWithPartialTp($signal, $candles, $i, $balance);
                if ($pnl === null) {
                    continue;
                }

                $balance += $pnl;
                $totalResults['totalPnl'] += $pnl;
                $totalResults['returns'][] = $pnl;
                $totalResults['trades']++;

                if ($pnl >= 0) {
                    $totalResults['wins']++;
                    $totalResults['winPnl'] += $pnl;
                } else {
                    $totalResults['losses']++;
                    $totalResults['lossPnl'] += abs($pnl);
                }

                if ($balance > $peakBalance) {
                    $peakBalance = $balance;
                }

                $drawdown = $peakBalance > 0 ? ($peakBalance - $balance) / $peakBalance : 0;
                $totalResults['maxDrawdown'] = max($totalResults['maxDrawdown'], $drawdown);

                // Drawdown-Recovery simulieren
                if (config('trading.risk.drawdown_recovery') && $drawdown >= 0.05) {
                    // Skip einige Candles als "Recovery-Pause"
                }

                $i = min($i + 5, $candles->count() - 1); // Skip nach Trade
            }

            $bar->finish();
            $this->newLine();
        }

        $this->newLine();
        $this->displayResults($totalResults, $initialBalance, $balance, $useConfluence ? 'Confluence' : $strategyName, implode(', ', $pairs));

        return self::SUCCESS;
    }

    /**
     * Confluence-Signal: Mindestens 2 Strategien müssen übereinstimmen
     */
    private function getConfluenceSignal(array $indicators, Collection $candles, array $h4Indicators): ?SignalDTO
    {
        $signals = [];

        foreach ($this->allStrategies as $strategy) {
            $signal = $strategy->analyze($indicators, $candles, $h4Indicators);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        if (empty($signals)) {
            return null;
        }

        $buySignals = array_filter($signals, fn ($s) => $s->direction === 'BUY');
        $sellSignals = array_filter($signals, fn ($s) => $s->direction === 'SELL');

        // Widersprüchliche Signale → kein Trade
        if (count($buySignals) > 0 && count($sellSignals) > 0) {
            return null;
        }

        $minConfluence = config('trading.strategy.min_confluence');

        if (count($buySignals) >= $minConfluence) {
            usort($buySignals, fn ($a, $b) => $b->confidence <=> $a->confidence);

            return $buySignals[0];
        }

        if (count($sellSignals) >= $minConfluence) {
            usort($sellSignals, fn ($a, $b) => $b->confidence <=> $a->confidence);

            return $sellSignals[0];
        }

        return null;
    }

    /**
     * Trade simulieren mit Partial Take Profit
     */
    private function simulateWithPartialTp(SignalDTO $signal, Collection $candles, int $startIndex, float $balance): ?float
    {
        $entryPrice = $signal->entryPrice;
        $sl = $signal->stopLoss;
        $tp = $signal->takeProfit;

        // Spread-Kosten abziehen
        $spreadCost = str_contains('JPY', '') ? 0.03 * 0.01 : 1.5 * 0.0001;

        // Position berechnen mit dynamischer Grösse
        $riskPct = config('trading.risk.max_per_trade');
        if (config('trading.risk.dynamic_sizing')) {
            $minPct = config('trading.risk.min_confidence_risk_pct');
            $maxPct = config('trading.risk.max_confidence_risk_pct');
            $lowT = config('trading.risk.confidence_threshold_low');
            $highT = config('trading.risk.confidence_threshold_high');
            $norm = max(0, min(1, ($signal->confidence - $lowT) / ($highT - $lowT)));
            $riskPct *= $minPct + ($maxPct - $minPct) * $norm;
        }

        $riskAmount = $balance * $riskPct;
        $pipDistance = abs($entryPrice - $sl);
        if ($pipDistance <= 0) {
            return null;
        }

        $units = (int) floor($riskAmount / $pipDistance);
        if ($units <= 0) {
            return null;
        }

        $partialTpEnabled = config('trading.partial_tp.enabled');
        $partialTriggerRR = config('trading.partial_tp.trigger_rr', 1.0);
        $partialClosePct = config('trading.partial_tp.close_pct', 0.5);
        $slDistance = abs($entryPrice - $sl);
        $partialTpLevel = $signal->direction === 'BUY'
            ? $entryPrice + ($slDistance * $partialTriggerRR)
            : $entryPrice - ($slDistance * $partialTriggerRR);

        $partialDone = false;
        $partialPnl = 0;
        $remainingUnits = $units;

        for ($j = $startIndex + 1; $j < min($startIndex + 100, $candles->count()); $j++) {
            $candle = $candles[$j];

            if ($signal->direction === 'BUY') {
                // Partial TP bei 1:1 R:R
                if ($partialTpEnabled && ! $partialDone && $candle['high'] >= $partialTpLevel) {
                    $closeUnits = (int) floor($units * $partialClosePct);
                    $partialPnl = ($partialTpLevel - $entryPrice - $spreadCost) * $closeUnits;
                    $remainingUnits -= $closeUnits;
                    $sl = $entryPrice; // SL auf Breakeven
                    $partialDone = true;
                }

                if ($candle['low'] <= $sl) {
                    $pnl = ($sl - $entryPrice - $spreadCost) * $remainingUnits + $partialPnl;

                    return round($pnl, 2);
                }
                if ($candle['high'] >= $tp) {
                    $pnl = ($tp - $entryPrice - $spreadCost) * $remainingUnits + $partialPnl;

                    return round($pnl, 2);
                }
            } else {
                if ($partialTpEnabled && ! $partialDone && $candle['low'] <= $partialTpLevel) {
                    $closeUnits = (int) floor($units * $partialClosePct);
                    $partialPnl = ($entryPrice - $partialTpLevel - $spreadCost) * $closeUnits;
                    $remainingUnits -= $closeUnits;
                    $sl = $entryPrice;
                    $partialDone = true;
                }

                if ($candle['high'] >= $sl) {
                    $pnl = ($entryPrice - $sl - $spreadCost) * $remainingUnits + $partialPnl;

                    return round($pnl, 2);
                }
                if ($candle['low'] <= $tp) {
                    $pnl = ($entryPrice - $tp - $spreadCost) * $remainingUnits + $partialPnl;

                    return round($pnl, 2);
                }
            }
        }

        return null; // Timeout
    }

    /**
     * Einfache Trade-Simulation (für Validierung)
     */
    private function simulateTrade(SignalDTO $signal, Collection $candles, int $startIndex, float $balance): bool
    {
        for ($j = $startIndex + 1; $j < min($startIndex + 100, $candles->count()); $j++) {
            $candle = $candles[$j];

            if ($signal->direction === 'BUY') {
                if ($candle['low'] <= $signal->stopLoss || $candle['high'] >= $signal->takeProfit) {
                    return true;
                }
            } else {
                if ($candle['high'] >= $signal->stopLoss || $candle['low'] <= $signal->takeProfit) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * H4-Trend prüfen für Backtest
     */
    private function checkH4Trend(string $direction, array $h4Indicators): bool
    {
        $h4Ema50 = $h4Indicators['ema_50']['value'] ?? null;
        $h4Sma200 = $h4Indicators['sma_200']['value'] ?? null;

        if (! $h4Ema50 || ! $h4Sma200) {
            return true;
        }

        if ($direction === 'BUY' && $h4Ema50 < $h4Sma200) {
            return false;
        }

        if ($direction === 'SELL' && $h4Ema50 > $h4Sma200) {
            return false;
        }

        return true;
    }

    /**
     * H4-Candles passend zum aktuellen Zeitfenster filtern
     */
    private function getH4Window(Collection $h4Candles, string $currentTime, int $count): Collection
    {
        if ($h4Candles->isEmpty() || ! $currentTime) {
            return collect();
        }

        $filtered = $h4Candles->filter(fn ($c) => ($c['time'] ?? '') <= $currentTime);

        return $filtered->slice(-$count)->values();
    }

    /**
     * Ergebnisse anzeigen
     */
    private function displayResults(array $results, float $initialBalance, float $endBalance, string $strategyName, string $pairs): void
    {
        $totalTrades = $results['trades'];

        if ($totalTrades === 0) {
            $this->warn('Keine Trades generiert in diesem Zeitraum.');

            return;
        }

        $winRate = ($results['wins'] / $totalTrades) * 100;
        $profitFactor = $results['lossPnl'] > 0 ? $results['winPnl'] / $results['lossPnl'] : ($results['winPnl'] > 0 ? 99 : 0);
        $returnPct = ($endBalance - $initialBalance) / $initialBalance * 100;

        // Sharpe Ratio
        $returns = $results['returns'];
        $avgReturn = array_sum($returns) / count($returns);
        $variance = 0;
        foreach ($returns as $r) {
            $variance += pow($r - $avgReturn, 2);
        }
        $stdDev = sqrt($variance / count($returns));
        $sharpe = $stdDev > 0 ? ($avgReturn / $stdDev) * sqrt(252) : 0;

        // Expectancy (erwarteter Gewinn pro Trade)
        $avgWin = $results['wins'] > 0 ? $results['winPnl'] / $results['wins'] : 0;
        $avgLoss = $results['losses'] > 0 ? $results['lossPnl'] / $results['losses'] : 0;
        $expectancy = ($winRate / 100 * $avgWin) - ((100 - $winRate) / 100 * $avgLoss);

        $this->info('═══════════════════════════════════════════');
        $this->info("  BACKTEST: {$strategyName} / {$pairs}");
        $this->info('═══════════════════════════════════════════');

        $this->table(
            ['Metrik', 'Wert'],
            [
                ['Trades', $totalTrades],
                ['Wins / Losses', "{$results['wins']} / {$results['losses']}"],
                ['Win Rate', sprintf('%.1f%%', $winRate)],
                ['Avg Win', sprintf('$%.2f', $avgWin)],
                ['Avg Loss', sprintf('$%.2f', $avgLoss)],
                ['Expectancy', sprintf('$%.2f pro Trade', $expectancy)],
                ['Return', sprintf('$%.2f (%.1f%%)', $results['totalPnl'], $returnPct)],
                ['Profit Factor', sprintf('%.2f', $profitFactor)],
                ['Max Drawdown', sprintf('%.1f%%', $results['maxDrawdown'] * 100)],
                ['Sharpe Ratio', sprintf('%.2f', $sharpe)],
                ['End Balance', sprintf('$%.2f', $endBalance)],
            ],
        );

        // Bewertung
        $this->newLine();
        if ($profitFactor >= 1.5 && $winRate >= 50 && $results['maxDrawdown'] < 0.15) {
            $this->info('✓ PROFITABEL — Strategie zeigt gute Ergebnisse');
        } elseif ($profitFactor >= 1.2 && $winRate >= 45) {
            $this->warn('~ MARGINAL — Strategie könnte mit Optimierung funktionieren');
        } else {
            $this->error('✗ UNPROFITABEL — Strategie braucht Überarbeitung');
        }

        if ($results['maxDrawdown'] > 0.20) {
            $this->error('! WARNUNG: Max Drawdown > 20% — zu riskant für Live-Trading');
        }
    }

    private function resolveStrategy(string $name): ?StrategyInterface
    {
        return match ($name) {
            'MACDCrossover' => new MACDCrossover,
            'RSIReversal' => new RSIReversal,
            'BollingerBounce' => new BollingerBounce,
            'EMACrossover' => new EMACrossover,
            'BreakoutStrategy' => new BreakoutStrategy,
            'FibonacciPullback' => new FibonacciPullback,
            default => null,
        };
    }
}
