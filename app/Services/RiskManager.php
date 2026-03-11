<?php

namespace App\Services;

use App\DTOs\SignalDTO;
use App\Events\DrawdownAlert;
use App\Models\BotState;
use App\Models\Trade;
use App\Models\TradingRule;
use App\Services\Broker\OandaClient;
use App\Services\Indicators\ATR;
use Illuminate\Support\Facades\Log;

class RiskManager
{
    private OandaClient $broker;

    public function __construct(OandaClient $broker)
    {
        $this->broker = $broker;
    }

    /**
     * Positionsgrösse berechnen basierend auf Risiko
     */
    public function calculatePositionSize(float $balance, float $entry, float $sl): int
    {
        $riskAmount = $balance * config('trading.risk.max_per_trade');
        $pipDistance = abs($entry - $sl);

        if ($pipDistance <= 0) {
            return 0;
        }

        $units = (int) floor($riskAmount / $pipDistance);

        // Maximal 100.000 Units (1 Standard-Lot)
        return min($units, 100000);
    }

    /**
     * Alle Pre-Trade-Bedingungen prüfen
     *
     * @return array{passed: bool, failures: string[]}
     */
    public function checkPreTradeConditions(SignalDTO $signal, string $pair): array
    {
        $log = Log::channel('trading');
        $failures = [];

        // Bot nicht pausiert?
        if (BotState::isPaused()) {
            $failures[] = 'Bot ist pausiert';
        }

        // Spread prüfen
        try {
            $price = $this->broker->getCurrentPrice($pair);
            $spread = ($price['ask'] - $price['bid']);
            $spreadPips = $this->toPips($spread, $pair);
            if ($spreadPips > config('trading.bot.max_spread_pips')) {
                $failures[] = "Spread zu hoch: {$spreadPips} Pips (max: ".config('trading.bot.max_spread_pips').')';
            }
        } catch (\Exception $e) {
            $failures[] = 'Preis konnte nicht abgerufen werden';
        }

        // Offene Trades prüfen
        $openTrades = Trade::open()->count();
        if ($openTrades >= config('trading.risk.max_open_trades')) {
            $failures[] = "Max offene Trades erreicht: {$openTrades}/".config('trading.risk.max_open_trades');
        }

        // Täglicher Verlust
        $dailyPnl = Trade::closed()->today()->sum('pnl');
        $account = $this->broker->getAccountInfo();
        $balance = (float) ($account['balance'] ?? 0);

        if ($balance > 0) {
            $dailyLossPct = abs(min(0, $dailyPnl)) / $balance;
            if ($dailyLossPct >= config('trading.risk.max_daily_loss')) {
                $failures[] = sprintf('Täglicher Verlust: %.1f%% (max: %.1f%%)', $dailyLossPct * 100, config('trading.risk.max_daily_loss') * 100);
            }
        }

        // Risk/Reward prüfen
        $rr = $signal->riskRewardRatio();
        if ($rr < config('trading.risk.min_rr_ratio')) {
            $failures[] = sprintf('R:R zu niedrig: %.2f (min: %.1f)', $rr, config('trading.risk.min_rr_ratio'));
        }

        // Korrelierte Paare prüfen
        $openPairs = Trade::open()->pluck('pair')->toArray();
        foreach (config('trading.correlated_pairs', []) as $group) {
            if (in_array($pair, $group)) {
                foreach ($group as $correlated) {
                    if ($correlated !== $pair && in_array($correlated, $openPairs)) {
                        $failures[] = "Korreliertes Pair bereits offen: {$correlated}";
                        break;
                    }
                }
            }
        }

        // TradingRules prüfen
        $lastCandle = null; // Wird vom Caller gesetzt
        $activeRules = TradingRule::active()->get();
        foreach ($activeRules as $rule) {
            if ($rule->blocks($signal->strategy, $pair, '', [])) {
                $failures[] = "Regel blockiert: {$rule->name}";
                $rule->increment('trades_prevented');
            }
        }

        $passed = empty($failures);

        if ($passed) {
            $log->info(sprintf(
                '[RISK] Pre-check PASSED — Risk: %.1f%%, R:R %.1f, Spread: %.1f Pips',
                config('trading.risk.max_per_trade') * 100,
                $rr,
                $spreadPips ?? 0,
            ));
        } else {
            $log->warning('[RISK] Pre-check FAILED — '.implode(', ', $failures));
        }

        return ['passed' => $passed, 'failures' => $failures];
    }

    /**
     * Offenen Trade managen (Trailing Stop, Drawdown prüfen)
     */
    public function manageOpenTrade(Trade $trade, float $currentPrice): void
    {
        $log = Log::channel('trading');

        // Max Favorable / Adverse berechnen
        if ($trade->direction === 'BUY') {
            $unrealized = $currentPrice - $trade->entry_price;
        } else {
            $unrealized = $trade->entry_price - $currentPrice;
        }

        // Max Favorable/Adverse updaten
        if ($unrealized > ($trade->max_favorable ?? 0)) {
            $trade->max_favorable = $currentPrice;
        }
        if ($unrealized < 0 && abs($unrealized) > abs($trade->max_adverse ?? 0)) {
            $trade->max_adverse = $currentPrice;
        }

        // Notfall: > 20% Kontoverlust durch diesen Trade
        $account = $this->broker->getAccountInfo();
        $balance = (float) ($account['balance'] ?? 0);

        if ($balance > 0 && $unrealized < 0) {
            $lossPct = abs($unrealized * $trade->position_size) / $balance;
            if ($lossPct > 0.20) {
                $log->critical("[RISK] Trade {$trade->pair} — Verlust > 20% des Kontos — SOFORT SCHLIESSEN");
                $this->broker->closeTrade($trade->oanda_trade_id);

                return;
            }
        }

        // Trailing Stop
        if (config('trading.risk.trailing_stop') && $unrealized > 0) {
            $atrMultiplier = (float) BotState::getValue('adaptive_trailing_multiplier', config('trading.risk.atr_trailing_multiplier'));
            $atrValue = $this->getLatestAtr($trade->pair);

            if ($atrValue > 0) {
                $trailingDistance = $atrValue * $atrMultiplier;

                if ($trade->direction === 'BUY') {
                    $newSl = $currentPrice - $trailingDistance;
                    if ($newSl > $trade->stop_loss) {
                        $this->broker->modifyTrade($trade->oanda_trade_id, $newSl, null);
                        $trade->stop_loss = $newSl;
                        $log->debug("[TRAIL] {$trade->pair} SL nachgezogen auf {$newSl}");
                    }
                } else {
                    $newSl = $currentPrice + $trailingDistance;
                    if ($newSl < $trade->stop_loss) {
                        $this->broker->modifyTrade($trade->oanda_trade_id, $newSl, null);
                        $trade->stop_loss = $newSl;
                        $log->debug("[TRAIL] {$trade->pair} SL nachgezogen auf {$newSl}");
                    }
                }
            }
        }

        $trade->save();
    }

    /**
     * Tägliche Verlustlimits prüfen
     */
    public function checkDailyLimits(): void
    {
        $log = Log::channel('trading');
        $dailyPnl = Trade::closed()->today()->sum('pnl');
        $account = $this->broker->getAccountInfo();
        $balance = (float) ($account['balance'] ?? 0);

        if ($balance <= 0) {
            return;
        }

        $dailyLossPct = abs(min(0, $dailyPnl)) / $balance;

        if ($dailyLossPct >= config('trading.risk.max_daily_loss')) {
            $log->warning(sprintf(
                '[RISK] Täglicher Verlust %.1f%% — LIMIT ERREICHT — Bot pausiert bis Mitternacht',
                $dailyLossPct * 100,
            ));

            // Alle offenen Trades schliessen
            $openTrades = Trade::open()->get();
            foreach ($openTrades as $trade) {
                if ($trade->oanda_trade_id) {
                    $this->broker->closeTrade($trade->oanda_trade_id);
                }
            }

            BotState::setValue('is_paused', 'true');
            BotState::setValue('pause_reason', 'daily_loss_limit');
            BotState::setValue('pause_until', now()->endOfDay()->toIso8601String());
        }

        if ($dailyLossPct >= config('trading.risk.max_daily_loss') * 0.8) {
            $log->warning(sprintf('[RISK] Täglicher Verlust %.1f%% — nähert sich Limit (%.1f%%)', $dailyLossPct * 100, config('trading.risk.max_daily_loss') * 100));
        }
    }

    /**
     * Gesamt-Drawdown prüfen
     */
    public function checkDrawdown(float $currentBalance): void
    {
        $peakBalance = BotState::getPeakBalance();

        // Peak aktualisieren
        if ($currentBalance > $peakBalance) {
            BotState::setValue('peak_balance', (string) $currentBalance);

            return;
        }

        if ($peakBalance <= 0) {
            return;
        }

        $drawdown = ($peakBalance - $currentBalance) / $peakBalance;

        if ($drawdown >= config('trading.risk.max_drawdown')) {
            BotState::setValue('is_running', 'false');
            BotState::setValue('stop_reason', 'max_drawdown');

            DrawdownAlert::dispatch($currentBalance, $peakBalance, $drawdown);
        }
    }

    /**
     * Spread in Pips umrechnen
     */
    private function toPips(float $value, string $pair): float
    {
        $pipSize = str_contains($pair, 'JPY') ? 0.01 : 0.0001;

        return round($value / $pipSize, 1);
    }

    /**
     * Aktuellen ATR-Wert für ein Pair holen
     */
    private function getLatestAtr(string $pair): float
    {
        $candles = $this->broker->getCandles($pair, config('trading.timeframe'), 20);
        if ($candles->isEmpty()) {
            return 0;
        }

        $atr = new ATR;

        return $atr->calculate($candles)['value'];
    }
}
