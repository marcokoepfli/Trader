<?php

namespace App\Listeners;

use App\Events\TradeClosed;
use App\Services\LearningEngine;

class UpdateLearningEngine
{
    public function __construct(private LearningEngine $engine) {}

    public function handle(TradeClosed $event): void
    {
        $this->engine->onTradeClosed($event->trade);
    }
}
