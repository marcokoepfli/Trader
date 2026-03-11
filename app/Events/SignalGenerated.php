<?php

namespace App\Events;

use App\Models\Signal;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SignalGenerated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Signal $signal) {}
}
