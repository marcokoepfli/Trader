<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DrawdownAlert
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public float $currentBalance,
        public float $peakBalance,
        public float $drawdownPct,
    ) {}
}
