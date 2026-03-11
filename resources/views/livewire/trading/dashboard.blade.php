<div wire:poll.10s="refreshData">
    {{-- Header Stats --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
        <div class="rounded-xl bg-gradient-to-br from-slate-800/60 to-slate-900/60 p-4 border border-slate-700/40">
            <p class="text-xs text-slate-400 mb-1">Balance</p>
            <p class="text-xl font-bold text-white">${{ number_format($balance, 2) }}</p>
        </div>
        <div class="rounded-xl bg-gradient-to-br from-slate-800/60 to-slate-900/60 p-4 border border-slate-700/40">
            <p class="text-xs text-slate-400 mb-1">Unrealisiert</p>
            <p class="text-xl font-bold {{ $unrealizedPnl >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                {{ $unrealizedPnl >= 0 ? '+' : '' }}${{ number_format($unrealizedPnl, 2) }}
            </p>
        </div>
        <div class="rounded-xl bg-gradient-to-br from-slate-800/60 to-slate-900/60 p-4 border border-slate-700/40">
            <p class="text-xs text-slate-400 mb-1">Heute P&L</p>
            <p class="text-xl font-bold {{ $todayPnl >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                {{ $todayPnl >= 0 ? '+' : '' }}${{ number_format($todayPnl, 2) }}
            </p>
        </div>
        <div class="rounded-xl bg-gradient-to-br from-slate-800/60 to-slate-900/60 p-4 border border-slate-700/40">
            <p class="text-xs text-slate-400 mb-1">Total Trades</p>
            <p class="text-xl font-bold text-white">{{ $totalTrades }}</p>
        </div>
        <div class="rounded-xl bg-gradient-to-br from-slate-800/60 to-slate-900/60 p-4 border border-slate-700/40">
            <p class="text-xs text-slate-400 mb-1">Win Rate</p>
            <p class="text-xl font-bold {{ $winRate >= 50 ? 'text-emerald-400' : 'text-rose-400' }}">{{ $winRate }}%</p>
        </div>
    </div>

    {{-- Main Grid --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <livewire:trading.bot-controls />
        <livewire:trading.risk-monitor />
        <livewire:trading.live-signals />
    </div>

    {{-- Equity Curve --}}
    <div class="mb-6">
        <livewire:trading.equity-curve />
    </div>

    {{-- Bottom Grid --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <livewire:trading.strategy-scores />
        <livewire:trading.active-rules />
    </div>

    {{-- Trade Log --}}
    <div class="mb-6">
        <livewire:trading.trade-log />
    </div>

    {{-- Reports --}}
    <div class="mb-6">
        <livewire:trading.reports />
    </div>
</div>
