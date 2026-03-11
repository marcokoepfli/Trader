<?php

namespace App\Console\Commands;

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

class BacktestCommand extends Command
{
    protected $signature = 'bot:backtest {strategy} {pair} {--months=3}';

    protected $description = 'Strategie backtesten mit historischen Daten';

    public function handle(OandaClient $broker, IndicatorService $indicatorService): int
    {
        $strategyName = $this->argument('strategy');
        $pair = $this->argument('pair');
        $months = (int) $this->option('months');

        $strategy = $this->resolveStrategy($strategyName);
        if (! $strategy) {
            $this->error("Strategie '{$strategyName}' nicht gefunden.");
            $this->info('Verfügbar: MACDCrossover, RSIReversal, BollingerBounce, EMACrossover, BreakoutStrategy, FibonacciPullback');

            return self::FAILURE;
        }

        $this->info("Backtesting: {$strategyName} auf {$pair} ({$months} Monate)");
        $this->newLine();

        // Historische Daten holen (max 5000 Candles)
        $candleCount = min($months * 30 * 24, 5000); // H1 Candles
        $candles = $broker->getCandles($pair, config('trading.timeframe'), $candleCount);

        if ($candles->count() < 100) {
            $this->error("Nicht genug historische Daten ({$candles->count()} Candles).");

            return self::FAILURE;
        }

        $this->info("Daten geladen: {$candles->count()} Candles");

        // Simulation
        $balance = 10000.0;
        $initialBalance = $balance;
        $trades = [];
        $wins = 0;
        $losses = 0;
        $totalPnl = 0;
        $maxDrawdown = 0;
        $peakBalance = $balance;
        $winPnl = 0;
        $lossPnl = 0;
        $returns = [];

        $windowSize = 200;
        $bar = $this->output->createProgressBar($candles->count() - $windowSize);

        for ($i = $windowSize; $i < $candles->count(); $i++) {
            $bar->advance();

            $window = $candles->slice($i - $windowSize, $windowSize)->values();
            $indicators = $indicatorService->calculateAll($window);

            $signal = $strategy->analyze($indicators, $window, []);

            if ($signal) {
                // Trade simulieren
                $entryPrice = $signal->entryPrice;
                $sl = $signal->stopLoss;
                $tp = $signal->takeProfit;
                $riskAmount = $balance * config('trading.risk.max_per_trade');
                $pipDistance = abs($entryPrice - $sl);

                if ($pipDistance <= 0) {
                    continue;
                }

                $units = (int) floor($riskAmount / $pipDistance);

                // Prüfe zukünftige Candles für Ergebnis
                $hitSl = false;
                $hitTp = false;

                for ($j = $i + 1; $j < min($i + 100, $candles->count()); $j++) {
                    $candle = $candles[$j];

                    if ($signal->direction === 'BUY') {
                        if ($candle['low'] <= $sl) {
                            $hitSl = true;
                            break;
                        }
                        if ($candle['high'] >= $tp) {
                            $hitTp = true;
                            break;
                        }
                    } else {
                        if ($candle['high'] >= $sl) {
                            $hitSl = true;
                            break;
                        }
                        if ($candle['low'] <= $tp) {
                            $hitTp = true;
                            break;
                        }
                    }
                }

                if ($hitTp) {
                    $pnl = abs($tp - $entryPrice) * $units;
                    $wins++;
                    $winPnl += $pnl;
                } elseif ($hitSl) {
                    $pnl = -abs($sl - $entryPrice) * $units;
                    $losses++;
                    $lossPnl += abs($pnl);
                } else {
                    continue; // Timeout — Trade nicht beendet
                }

                $balance += $pnl;
                $totalPnl += $pnl;
                $returns[] = $pnl;

                if ($balance > $peakBalance) {
                    $peakBalance = $balance;
                }

                $drawdown = $peakBalance > 0 ? ($peakBalance - $balance) / $peakBalance : 0;
                $maxDrawdown = max($maxDrawdown, $drawdown);

                $trades[] = [
                    'direction' => $signal->direction,
                    'pnl' => $pnl,
                    'result' => $hitTp ? 'WIN' : 'LOSS',
                ];

                // Skip einige Candles nach Trade
                $i = $j;
            }
        }

        $bar->finish();
        $this->newLine(2);

        $totalTrades = count($trades);

        if ($totalTrades === 0) {
            $this->warn('Keine Trades generiert in diesem Zeitraum.');

            return self::SUCCESS;
        }

        $winRate = $totalTrades > 0 ? ($wins / $totalTrades) * 100 : 0;
        $profitFactor = $lossPnl > 0 ? $winPnl / $lossPnl : ($winPnl > 0 ? 99 : 0);
        $returnPct = ($balance - $initialBalance) / $initialBalance * 100;

        // Sharpe Ratio
        $avgReturn = array_sum($returns) / count($returns);
        $variance = 0;
        foreach ($returns as $r) {
            $variance += pow($r - $avgReturn, 2);
        }
        $stdDev = sqrt($variance / count($returns));
        $sharpe = $stdDev > 0 ? ($avgReturn / $stdDev) * sqrt(252) : 0;

        $this->info('═══════════════════════════════════════');
        $this->info("  BACKTEST ERGEBNIS: {$strategyName} / {$pair}");
        $this->info('═══════════════════════════════════════');

        $this->table(
            ['Metrik', 'Wert'],
            [
                ['Trades', $totalTrades],
                ['Wins', $wins],
                ['Losses', $losses],
                ['Win Rate', sprintf('%.1f%%', $winRate)],
                ['Return', sprintf('$%.2f (%.1f%%)', $totalPnl, $returnPct)],
                ['Profit Factor', sprintf('%.2f', $profitFactor)],
                ['Max Drawdown', sprintf('%.1f%%', $maxDrawdown * 100)],
                ['Sharpe Ratio', sprintf('%.2f', $sharpe)],
                ['End Balance', sprintf('$%.2f', $balance)],
            ],
        );

        return self::SUCCESS;
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
