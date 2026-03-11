<?php

namespace App\Livewire\Trading;

use App\Models\TradingRule;
use Livewire\Component;

class ActiveRules extends Component
{
    public string $newName = '';

    public string $newDescription = '';

    public string $newType = 'strategy_pause';

    public string $newReason = '';

    public bool $showForm = false;

    public function toggleRule(int $id): void
    {
        $rule = TradingRule::findOrFail($id);
        $rule->update(['active' => ! $rule->active]);
    }

    public function addRule(): void
    {
        $this->validate([
            'newName' => 'required|string|max:255',
            'newDescription' => 'required|string',
            'newType' => 'required|string',
            'newReason' => 'required|string',
        ]);

        TradingRule::query()->create([
            'name' => $this->newName,
            'description' => $this->newDescription,
            'type' => $this->newType,
            'conditions' => [],
            'reason' => $this->newReason,
            'source' => 'manual',
        ]);

        $this->reset(['newName', 'newDescription', 'newReason', 'showForm']);
    }

    public function deleteRule(int $id): void
    {
        TradingRule::query()->where('id', $id)->where('source', 'manual')->delete();
    }

    public function render()
    {
        $rules = TradingRule::query()->latest()->get();

        return view('livewire.trading.active-rules', compact('rules'));
    }
}
