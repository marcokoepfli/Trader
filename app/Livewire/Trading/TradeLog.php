<?php

namespace App\Livewire\Trading;

use App\Models\Trade;
use Livewire\Component;
use Livewire\WithPagination;

class TradeLog extends Component
{
    use WithPagination;

    public string $filterPair = '';

    public string $filterStrategy = '';

    public string $filterResult = '';

    public string $sortBy = 'opened_at';

    public string $sortDir = 'desc';

    public function updatedFilterPair(): void
    {
        $this->resetPage();
    }

    public function updatedFilterStrategy(): void
    {
        $this->resetPage();
    }

    public function updatedFilterResult(): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'desc';
        }
    }

    public function render()
    {
        $trades = Trade::query()
            ->when($this->filterPair, fn ($q) => $q->where('pair', $this->filterPair))
            ->when($this->filterStrategy, fn ($q) => $q->where('strategy', 'like', "%{$this->filterStrategy}%"))
            ->when($this->filterResult, fn ($q) => $q->where('result', $this->filterResult))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(15);

        $pairs = config('trading.pairs');

        return view('livewire.trading.trade-log', compact('trades', 'pairs'));
    }
}
