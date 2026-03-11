<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;

class LogTradeActivity
{
    public function handle(object $event): void
    {
        $trade = $event->trade;
        $log = Log::channel('trading');

        if ($trade->isOpen()) {
            $log->info(sprintf(
                '[TRADE] OPENED %s %s @ %s — SL: %s, TP: %s, Size: %d',
                $trade->pair,
                $trade->direction,
                $trade->entry_price,
                $trade->stop_loss,
                $trade->take_profit,
                $trade->position_size,
            ));
        } else {
            $pnlSign = $trade->pnl >= 0 ? '+' : '';
            $log->info(sprintf(
                '[TRADE] CLOSED %s — %s %s$%.2f (%.2f%%) — %s',
                $trade->pair,
                $trade->result,
                $pnlSign,
                $trade->pnl,
                $trade->pnl_pct,
                $trade->hit_stop_loss ? 'Hit SL' : ($trade->hit_take_profit ? 'Hit TP' : 'Manuell'),
            ));
        }
    }
}
