<?php

namespace App\Console\Commands;

use App\Services\LearningEngine;
use Illuminate\Console\Command;

class WeeklyReportCommand extends Command
{
    protected $signature = 'bot:report';

    protected $description = 'Trading-Report generieren';

    public function handle(LearningEngine $engine): int
    {
        $report = $engine->generateWeeklyReport();

        $this->info('═══════════════════════════════════════');
        $this->info('  TRADING REPORT');
        $this->info('═══════════════════════════════════════');

        $this->table(
            ['Metrik', 'Wert'],
            [
                ['Datum', $report->report_date->format('d.m.Y')],
                ['Start-Balance', sprintf('$%.2f', $report->starting_balance)],
                ['End-Balance', sprintf('$%.2f', $report->ending_balance)],
                ['P&L', sprintf('$%.2f (%.2f%%)', $report->daily_pnl, $report->daily_pnl_pct)],
                ['Trades', $report->trades_closed],
                ['Wins/Losses', "{$report->wins}/{$report->losses}"],
            ],
        );

        if (! empty($report->strategy_breakdown)) {
            $this->newLine();
            $this->info('Strategie-Breakdown:');

            $rows = [];
            foreach ($report->strategy_breakdown as $name => $data) {
                $rows[] = [
                    $name,
                    sprintf('%.2f', $data['score'] ?? 0),
                    sprintf('%.0f%%', $data['win_rate'] ?? 0),
                    $data['trades'] ?? 0,
                    ($data['on_cooldown'] ?? false) ? 'JA' : 'Nein',
                ];
            }

            $this->table(['Strategie', 'Score', 'Win Rate', 'Trades', 'Cooldown'], $rows);
        }

        if (! empty($report->new_rules)) {
            $this->newLine();
            $this->info('Neue Regeln:');
            foreach ($report->new_rules as $rule) {
                $this->line("  → {$rule}");
            }
        }

        return self::SUCCESS;
    }
}
