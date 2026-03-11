<?php

namespace App\Events;

use App\Models\TradingRule;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RuleCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public TradingRule $rule) {}
}
