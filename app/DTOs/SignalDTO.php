<?php

namespace App\DTOs;

class SignalDTO
{
    public function __construct(
        public readonly string $direction,
        public readonly float $confidence,
        public readonly string $strategy,
        public readonly float $entryPrice,
        public readonly float $stopLoss,
        public readonly float $takeProfit,
        public readonly string $reasoning,
    ) {}

    public function riskRewardRatio(): float
    {
        $risk = abs($this->entryPrice - $this->stopLoss);
        if ($risk === 0.0) {
            return 0;
        }

        return abs($this->takeProfit - $this->entryPrice) / $risk;
    }

    public function toArray(): array
    {
        return [
            'direction' => $this->direction,
            'confidence' => $this->confidence,
            'strategy' => $this->strategy,
            'entry_price' => $this->entryPrice,
            'stop_loss' => $this->stopLoss,
            'take_profit' => $this->takeProfit,
            'reasoning' => $this->reasoning,
        ];
    }
}
