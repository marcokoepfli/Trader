<div class="rounded-2xl bg-gradient-to-br from-slate-800/80 to-slate-900/80 p-6 border border-slate-700/50 backdrop-blur-sm">
    <h3 class="text-lg font-bold text-white mb-4">Equity Kurve</h3>

    <div class="relative" style="height: 300px;">
        <canvas id="equityChart" wire:ignore></canvas>
    </div>
</div>

@script
<script>
    const ctx = document.getElementById('equityChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: $wire.labels,
                datasets: [
                    {
                        label: 'Equity ($)',
                        data: $wire.equity,
                        borderColor: '#00d2a0',
                        backgroundColor: 'rgba(0, 210, 160, 0.1)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 2,
                        pointRadius: 0,
                        pointHitRadius: 10,
                    },
                    {
                        label: 'Drawdown (%)',
                        data: $wire.drawdown,
                        borderColor: '#ff4757',
                        backgroundColor: 'rgba(255, 71, 87, 0.05)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 1,
                        pointRadius: 0,
                        yAxisID: 'y1',
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { labels: { color: '#94a3b8', font: { size: 11 } } },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        titleColor: '#e2e8f0',
                        bodyColor: '#94a3b8',
                        borderColor: '#334155',
                        borderWidth: 1,
                    }
                },
                scales: {
                    x: { display: false },
                    y: {
                        position: 'left',
                        grid: { color: '#1e293b' },
                        ticks: { color: '#64748b', callback: v => '$' + v }
                    },
                    y1: {
                        position: 'right',
                        grid: { display: false },
                        ticks: { color: '#64748b', callback: v => v + '%' },
                        reverse: true,
                    }
                }
            }
        });
    }
</script>
@endscript
