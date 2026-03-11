<?php

namespace App\Console\Commands;

use App\Models\BotState;
use App\Models\Trade;
use Illuminate\Console\Command;

class BotStopCommand extends Command
{
    protected $signature = 'bot:stop';

    protected $description = 'Trading Bot stoppen';

    public function handle(): int
    {
        BotState::setValue('is_running', 'false');
        BotState::setValue('stopped_at', now()->toIso8601String());

        $openTrades = Trade::open()->get();

        $this->info('✓ Bot gestoppt.');
        $this->newLine();

        if ($openTrades->isNotEmpty()) {
            $this->warn("Offene Trades ({$openTrades->count()}):");

            $rows = $openTrades->map(fn ($t) => [
                $t->pair,
                $t->direction,
                $t->entry_price,
                $t->stop_loss,
                $t->take_profit,
                $t->strategy,
            ])->toArray();

            $this->table(
                ['Pair', 'Richtung', 'Entry', 'SL', 'TP', 'Strategie'],
                $rows,
            );

            $this->comment('Offene Trades laufen mit SL/TP bei OANDA weiter.');
        } else {
            $this->info('Keine offenen Trades.');
        }

        return self::SUCCESS;
    }
}
