<?php

namespace App\Livewire\Trading;

use App\Models\BotState;
use App\Services\Broker\OandaClient;
use Livewire\Component;

class BotControls extends Component
{
    public bool $isRunning = false;

    public bool $isPaused = false;

    public string $environment = 'practice';

    public float $balance = 0;

    public float $unrealizedPnl = 0;

    public string $startedAt = '';

    public string $pauseReason = '';

    public function mount(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        $this->isRunning = BotState::isRunning();
        $this->isPaused = BotState::isPaused();
        $this->environment = BotState::getValue('environment', 'practice');
        $this->startedAt = BotState::getValue('started_at', '');
        $this->pauseReason = BotState::getValue('pause_reason', '');

        try {
            $broker = app(OandaClient::class);
            $account = $broker->getAccountInfo();
            $this->balance = (float) ($account['balance'] ?? 0);
            $this->unrealizedPnl = (float) ($account['unrealizedPL'] ?? 0);
        } catch (\Exception) {
            // Verbindungsfehler
        }
    }

    public function startBot(): void
    {
        BotState::setValue('is_running', 'true');
        BotState::setValue('is_paused', 'false');
        BotState::setValue('started_at', now()->toIso8601String());
        $this->refresh();
    }

    public function stopBot(): void
    {
        BotState::setValue('is_running', 'false');
        BotState::setValue('stopped_at', now()->toIso8601String());
        $this->refresh();
    }

    public function pauseBot(): void
    {
        BotState::setValue('is_paused', 'true');
        BotState::setValue('pause_reason', 'Manuell pausiert');
        $this->refresh();
    }

    public function resumeBot(): void
    {
        BotState::setValue('is_paused', 'false');
        BotState::setValue('pause_reason', '');
        $this->refresh();
    }

    public function render()
    {
        return view('livewire.trading.bot-controls');
    }
}
