<?php

namespace App\Events;

use App\Models\Trade;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TradeClosed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Trade $trade) {}
}
