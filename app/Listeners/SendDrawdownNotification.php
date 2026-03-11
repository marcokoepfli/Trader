<?php

namespace App\Listeners;

use App\Events\DrawdownAlert;
use Illuminate\Support\Facades\Log;

class SendDrawdownNotification
{
    public function handle(DrawdownAlert $event): void
    {
        $log = Log::channel('trading');
        $log->critical(sprintf(
            '[RISK] DRAWDOWN %.1f%% ERREICHT — BOT GESTOPPT — Balance: $%.2f, Peak: $%.2f',
            $event->drawdownPct * 100,
            $event->currentBalance,
            $event->peakBalance,
        ));
    }
}
