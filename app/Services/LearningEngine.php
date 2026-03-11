<?php

namespace App\Services;

use App\Events\RuleCreated;
use App\Models\BotState;
use App\Models\DailyReport;
use App\Models\StrategyScore;
use App\Models\Trade;
use App\Models\TradingRule;
use Illuminate\Support\Facades\Log;

class LearningEngine
{
    private $log;

    public function __construct()
    {
        $this->log = Log::channel('trading');
    }

    /**
     * Wird nach jedem geschlossenen Trade aufgerufen
     */
    public function onTradeClosed(Trade $trade): void
    {
        $this->updateStrategyScores($trade->strategy);
        $this->detectConsecutiveLosses($trade->strategy);

        $minTrades = config('trading.learning.min_trades');
        $totalTrades = Trade::closed()->forStrategy($trade->strategy)->count();

        if ($totalTrades >= $minTrades) {
            $this->analyzeFailurePatterns($trade->strategy);
        }

        $this->evaluateRules();
    }

    /**
     * Strategie-Scores aktualisieren
     */
    public function updateStrategyScores(?string $strategyName = null): void
    {
        $strategies = $strategyName
            ? [$strategyName]
            : StrategyScore::query()->pluck('strategy')->toArray();

        foreach ($strategies as $strategy) {
            $trades = Trade::closed()->forStrategy($strategy)->get();
            $total = $trades->count();

            if ($total === 0) {
                continue;
            }

            $wins = $trades->where('result', 'WIN')->count();
            $losses = $trades->where('result', 'LOSS')->count();
            $winRate = $total > 0 ? $wins / $total : 0;

            $winningPnl = $trades->where('result', 'WIN')->sum('pnl');
            $losingPnl = abs($trades->where('result', 'LOSS')->sum('pnl'));
            $avgPnl = $trades->avg('pnl');
            $profitFactor = $losingPnl > 0 ? $winningPnl / $losingPnl : ($winningPnl > 0 ? 99.0 : 0);

            // Letzte 10 Trades für Recent Performance
            $recentTrades = Trade::closed()->forStrategy($strategy)->latest('closed_at')->limit(10)->get();
            $recentWinRate = $recentTrades->count() > 0
                ? $recentTrades->where('result', 'WIN')->count() / $recentTrades->count()
                : $winRate;

            // Score = 40% Win Rate + 30% Profit Factor (normalisiert) + 30% Recent Performance
            $normalizedPf = min($profitFactor / 3, 1); // Profit Factor 3+ = 1.0
            $score = ($winRate * 0.4) + ($normalizedPf * 0.3) + ($recentWinRate * 0.3);
            $score = round(min(1.0, max(0.0, $score)), 2);

            $oldScore = StrategyScore::query()->where('strategy', $strategy)->value('score') ?? 0.5;

            StrategyScore::query()->updateOrCreate(
                ['strategy' => $strategy],
                [
                    'score' => $score,
                    'win_rate' => round($winRate * 100, 2),
                    'avg_pnl' => round($avgPnl, 2),
                    'profit_factor' => round($profitFactor, 2),
                    'total_trades' => $total,
                    'wins' => $wins,
                    'losses' => $losses,
                    'on_cooldown' => $score < config('trading.strategy.min_score_to_trade'),
                ],
            );

            $this->log->info(sprintf('[LEARN] %s Score: %.2f → %.2f (WR: %.0f%%, PF: %.2f)', $strategy, $oldScore, $score, $winRate * 100, $profitFactor));
        }
    }

    /**
     * Aufeinanderfolgende Verluste erkennen und Cooldown aktivieren
     */
    public function detectConsecutiveLosses(string $strategy): void
    {
        $recentTrades = Trade::closed()
            ->forStrategy($strategy)
            ->latest('closed_at')
            ->limit(10)
            ->pluck('result')
            ->toArray();

        $consecutive = 0;
        foreach ($recentTrades as $result) {
            if ($result === 'LOSS') {
                $consecutive++;
            } else {
                break;
            }
        }

        $maxConsecutive = config('trading.strategy.cooldown_after_losses');

        if ($consecutive >= $maxConsecutive) {
            $cooldownHours = $consecutive >= 5 ? 24 : 6;
            $cooldownUntil = now()->addHours($cooldownHours);

            StrategyScore::query()->where('strategy', $strategy)->update([
                'consecutive_losses' => $consecutive,
                'on_cooldown' => true,
                'cooldown_until' => $cooldownUntil,
            ]);

            $this->log->warning(sprintf(
                '[LEARN] %s — %d Verluste in Folge — Cooldown bis %s',
                $strategy,
                $consecutive,
                $cooldownUntil->format('d.m.Y H:i'),
            ));

            // Bei 5+ Verlusten: Bot für 24h pausieren
            if ($consecutive >= 5) {
                BotState::setValue('is_paused', 'true');
                BotState::setValue('pause_reason', "5+ Verluste bei {$strategy}");
                BotState::setValue('pause_until', now()->addDay()->toIso8601String());
                $this->log->critical("[LEARN] Bot pausiert für 24h — {$consecutive} Verluste in Folge bei {$strategy}");
            }
        }
    }

    /**
     * Verlust-Muster analysieren und Regeln erstellen
     */
    public function analyzeFailurePatterns(string $strategy): void
    {
        $losses = Trade::losses()
            ->forStrategy($strategy)
            ->latest('closed_at')
            ->limit(20)
            ->get();

        if ($losses->count() < 5) {
            return;
        }

        $this->analyzeSessionPatterns($strategy, $losses);
        $this->analyzeIndicatorPatterns($strategy, $losses);
        $this->analyzeMarketConditions($strategy, $losses);
        $this->analyzeStopLossEfficiency($strategy);
        $this->analyzeTakeProfitEfficiency($strategy);
    }

    /**
     * Session-Analyse: Welche Session hat die meisten Verluste?
     */
    private function analyzeSessionPatterns(string $strategy, $losses): void
    {
        $sessionLosses = $losses->groupBy('session')->map->count();
        $totalLosses = $losses->count();

        foreach ($sessionLosses as $session => $count) {
            $ratio = $count / $totalLosses;

            // > 50% der Verluste in einer Session → Regel erstellen
            if ($ratio > 0.5 && $count >= 3) {
                $ruleName = "Kein {$strategy} in {$session} Session";

                // Prüfe ob Regel schon existiert
                if (TradingRule::query()->where('name', $ruleName)->exists()) {
                    continue;
                }

                $rule = TradingRule::query()->create([
                    'name' => $ruleName,
                    'description' => "{$count} von {$totalLosses} Verlusten ({$strategy}) fanden in der {$session} Session statt",
                    'type' => 'session_block',
                    'conditions' => ['strategy' => $strategy, 'session' => $session],
                    'reason' => sprintf('%.0f%% der Verluste in %s Session', $ratio * 100, $session),
                    'source' => 'auto',
                ]);

                RuleCreated::dispatch($rule);
                $this->log->info("[LEARN] REGEL ERSTELLT: \"{$ruleName}\"");
            }
        }
    }

    /**
     * Indikator-Muster bei Verlusten analysieren
     */
    private function analyzeIndicatorPatterns(string $strategy, $losses): void
    {
        // ADX-Werte bei Verlusten prüfen
        $adxValues = $losses->map(fn ($t) => $t->indicators_at_entry['adx']['value'] ?? null)->filter()->values();

        if ($adxValues->count() >= 3) {
            $avgAdx = $adxValues->avg();
            $lowAdxCount = $adxValues->filter(fn ($v) => $v < 15)->count();

            if ($lowAdxCount >= $adxValues->count() * 0.5) {
                $ruleName = 'Kein Trade wenn ADX < 15';

                if (! TradingRule::query()->where('name', $ruleName)->exists()) {
                    $rule = TradingRule::query()->create([
                        'name' => $ruleName,
                        'description' => 'Die meisten Verluste traten bei niedrigem ADX (< 15) auf',
                        'type' => 'indicator_filter',
                        'conditions' => ['indicator' => 'adx', 'operator' => '<', 'value' => 15],
                        'reason' => sprintf('%.0f%% der Verluste bei ADX < 15', ($lowAdxCount / $adxValues->count()) * 100),
                        'source' => 'auto',
                    ]);

                    RuleCreated::dispatch($rule);
                    $this->log->info("[LEARN] REGEL ERSTELLT: \"{$ruleName}\"");
                }
            }
        }

        // RSI-Werte bei BUY-Verlusten
        $buyLosses = $losses->where('direction', 'BUY');
        if ($buyLosses->count() >= 3) {
            $rsiValues = $buyLosses->map(fn ($t) => $t->indicators_at_entry['rsi']['value'] ?? null)->filter();
            $highRsiCount = $rsiValues->filter(fn ($v) => $v > 50)->count();

            if ($rsiValues->count() > 0 && $highRsiCount >= $rsiValues->count() * 0.6) {
                $ruleName = "Kein {$strategy} BUY wenn RSI > 50";

                if (! TradingRule::query()->where('name', $ruleName)->exists()) {
                    $rule = TradingRule::query()->create([
                        'name' => $ruleName,
                        'description' => "BUY-Verluste bei {$strategy} treten häufig bei RSI > 50 auf",
                        'type' => 'indicator_filter',
                        'conditions' => ['indicator' => 'rsi', 'operator' => '>', 'value' => 50],
                        'reason' => sprintf('%.0f%% der BUY-Verluste bei RSI > 50', ($highRsiCount / $rsiValues->count()) * 100),
                        'source' => 'auto',
                    ]);

                    RuleCreated::dispatch($rule);
                }
            }
        }
    }

    /**
     * Marktbedingungen bei Verlusten analysieren
     */
    private function analyzeMarketConditions(string $strategy, $losses): void
    {
        $conditionLosses = $losses->groupBy('market_condition')->map->count();
        $totalLosses = $losses->count();

        foreach ($conditionLosses as $condition => $count) {
            $ratio = $count / $totalLosses;

            if ($ratio > 0.6 && $count >= 3) {
                $ruleName = "Kein {$strategy} bei {$condition} Markt";

                if (! TradingRule::query()->where('name', $ruleName)->exists()) {
                    $rule = TradingRule::query()->create([
                        'name' => $ruleName,
                        'description' => "{$count} von {$totalLosses} Verlusten bei {$condition} Marktbedingungen",
                        'type' => 'market_condition',
                        'conditions' => ['market_condition' => $condition, 'strategy' => $strategy],
                        'reason' => sprintf('%.0f%% der Verluste bei %s Markt', $ratio * 100, $condition),
                        'source' => 'auto',
                    ]);

                    RuleCreated::dispatch($rule);
                }
            }
        }
    }

    /**
     * SL-Analyse: Werden SLs knapp getroffen?
     */
    private function analyzeStopLossEfficiency(string $strategy): void
    {
        $slTrades = Trade::losses()->forStrategy($strategy)->whereNotNull('max_adverse')->get();

        if ($slTrades->count() < 5) {
            return;
        }

        $closeHits = 0;
        foreach ($slTrades as $trade) {
            $slDistance = abs($trade->entry_price - $trade->stop_loss);
            $adverseDistance = abs($trade->entry_price - $trade->max_adverse);

            // SL wurde knapp getroffen (> 80% des SL-Abstands)
            if ($slDistance > 0 && $adverseDistance / $slDistance > 0.8) {
                $closeHits++;
            }
        }

        $closeHitRatio = $closeHits / $slTrades->count();

        if ($closeHitRatio > 0.6) {
            $currentMultiplier = (float) BotState::getValue('adaptive_sl_multiplier', config('trading.risk.atr_sl_multiplier'));
            $newMultiplier = min($currentMultiplier * 1.2, 3.0); // Max 3x ATR

            BotState::setValue('adaptive_sl_multiplier', (string) round($newMultiplier, 2));
            $this->log->info(sprintf('[LEARN] SL-Multiplier angepasst: %.2f → %.2f (%.0f%% knapp getroffen)', $currentMultiplier, $newMultiplier, $closeHitRatio * 100));
        }
    }

    /**
     * TP-Analyse: Kommt Preis nahe an TP und dreht um?
     */
    private function analyzeTakeProfitEfficiency(string $strategy): void
    {
        $lossTrades = Trade::losses()->forStrategy($strategy)->whereNotNull('max_favorable')->get();

        if ($lossTrades->count() < 5) {
            return;
        }

        $nearTpCount = 0;
        foreach ($lossTrades as $trade) {
            $tpDistance = abs($trade->take_profit - $trade->entry_price);
            $favorableDistance = abs($trade->max_favorable - $trade->entry_price);

            // Preis kam auf > 70% des TP
            if ($tpDistance > 0 && $favorableDistance / $tpDistance > 0.7) {
                $nearTpCount++;
            }
        }

        $nearTpRatio = $nearTpCount / $lossTrades->count();

        if ($nearTpRatio > 0.5) {
            $currentMultiplier = (float) BotState::getValue('adaptive_tp_multiplier', 1.0);
            $newMultiplier = max($currentMultiplier * 0.85, 0.5); // Min 50%

            BotState::setValue('adaptive_tp_multiplier', (string) round($newMultiplier, 2));
            $this->log->info(sprintf('[LEARN] TP-Multiplier angepasst: %.2f → %.2f (%.0f%% verpasste TPs)', $currentMultiplier, $newMultiplier, $nearTpRatio * 100));
        }
    }

    /**
     * Bestehende Regeln auf Wirksamkeit prüfen
     */
    public function evaluateRules(): void
    {
        $rules = TradingRule::active()->where('source', 'auto')->get();

        foreach ($rules as $rule) {
            // Regeln die nach 20+ blockierten Trades keine Ersparnisse bringen → deaktivieren
            if ($rule->trades_prevented >= 20 && $rule->estimated_savings <= 0) {
                $rule->update(['active' => false]);
                $this->log->info("[LEARN] Regel deaktiviert (kein Nutzen): \"{$rule->name}\"");
            }
        }
    }

    /**
     * Wöchentlichen Report generieren
     */
    public function generateWeeklyReport(): DailyReport
    {
        $startOfWeek = now()->startOfWeek();
        $endOfWeek = now()->endOfWeek();

        $trades = Trade::closed()
            ->whereBetween('closed_at', [$startOfWeek, $endOfWeek])
            ->get();

        $strategies = StrategyScore::all();
        $strategyBreakdown = [];
        foreach ($strategies as $score) {
            $strategyBreakdown[$score->strategy] = [
                'score' => $score->score,
                'win_rate' => $score->win_rate,
                'trades' => $score->total_trades,
                'on_cooldown' => $score->on_cooldown,
            ];
        }

        $pairBreakdown = $trades->groupBy('pair')->map(fn ($group) => [
            'trades' => $group->count(),
            'pnl' => round($group->sum('pnl'), 2),
            'wins' => $group->where('result', 'WIN')->count(),
        ])->toArray();

        $newRules = TradingRule::query()
            ->where('source', 'auto')
            ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
            ->pluck('name')
            ->toArray();

        $startBalance = (float) BotState::getValue('week_start_balance', 0);
        $endBalance = (float) (app(Broker\OandaClient::class)->getAccountInfo()['balance'] ?? 0);

        $report = DailyReport::query()->updateOrCreate(
            ['report_date' => now()->toDateString()],
            [
                'starting_balance' => $startBalance,
                'ending_balance' => $endBalance,
                'daily_pnl' => $trades->sum('pnl'),
                'daily_pnl_pct' => $startBalance > 0 ? ($trades->sum('pnl') / $startBalance) * 100 : 0,
                'trades_opened' => $trades->count(),
                'trades_closed' => $trades->count(),
                'wins' => $trades->where('result', 'WIN')->count(),
                'losses' => $trades->where('result', 'LOSS')->count(),
                'strategy_breakdown' => $strategyBreakdown,
                'pair_breakdown' => $pairBreakdown,
                'new_rules' => $newRules,
            ],
        );

        // Neuen Wochen-Start-Balance setzen
        BotState::setValue('week_start_balance', (string) $endBalance);

        $this->log->info(sprintf(
            '[REPORT] Wochenbericht: P&L: $%.2f, Trades: %d, WR: %.0f%%',
            $trades->sum('pnl'),
            $trades->count(),
            $trades->count() > 0 ? ($trades->where('result', 'WIN')->count() / $trades->count()) * 100 : 0,
        ));

        return $report;
    }
}
