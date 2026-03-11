<?php

namespace App\Livewire\Trading;

use App\Models\DailyReport;
use Livewire\Component;
use Livewire\WithPagination;

class Reports extends Component
{
    use WithPagination;

    public ?int $selectedReportId = null;

    public function selectReport(int $id): void
    {
        $this->selectedReportId = $this->selectedReportId === $id ? null : $id;
    }

    public function render()
    {
        $reports = DailyReport::query()
            ->orderByDesc('report_date')
            ->paginate(10);

        $selectedReport = $this->selectedReportId
            ? DailyReport::find($this->selectedReportId)
            : null;

        return view('livewire.trading.reports', compact('reports', 'selectedReport'));
    }
}
