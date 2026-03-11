<div class="rounded-2xl bg-gradient-to-br from-slate-800/80 to-slate-900/80 p-6 border border-slate-700/50 backdrop-blur-sm">
    <h3 class="text-lg font-bold text-white mb-4">Risiko Monitor</h3>

    {{-- Risk Score --}}
    <div class="text-center mb-5">
        <div class="relative inline-flex items-center justify-center w-24 h-24">
            <svg class="w-24 h-24 transform -rotate-90" viewBox="0 0 36 36">
                <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                    fill="none" stroke="#1e293b" stroke-width="3"/>
                <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                    fill="none"
                    stroke="{{ $riskScore > 70 ? '#ff4757' : ($riskScore > 40 ? '#e8b84b' : '#00d2a0') }}"
                    stroke-width="3"
                    stroke-dasharray="{{ $riskScore }}, 100"/>
            </svg>
            <span class="absolute text-xl font-bold {{ $riskScore > 70 ? 'text-rose-400' : ($riskScore > 40 ? 'text-amber-400' : 'text-emerald-400') }}">{{ $riskScore }}</span>
        </div>
        <p class="text-xs text-slate-400 mt-1">Risk Score</p>
    </div>

    {{-- Daily P&L --}}
    <div class="mb-4">
        <div class="flex justify-between text-sm mb-1">
            <span class="text-slate-400">Tages-P&L</span>
            <span class="{{ $dailyPnl >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">${{ number_format($dailyPnl, 2) }}</span>
        </div>
        <div class="w-full bg-slate-700/50 rounded-full h-2">
            <div class="h-2 rounded-full transition-all duration-500 {{ $dailyPnlPct > ($maxDailyLoss * 0.8) ? 'bg-rose-500' : 'bg-amber-500' }}"
                style="width: {{ min(100, ($dailyPnlPct / max(1, $maxDailyLoss)) * 100) }}%"></div>
        </div>
        <p class="text-xs text-slate-500 mt-1">{{ number_format($dailyPnlPct, 1) }}% / {{ $maxDailyLoss }}% max</p>
    </div>

    {{-- Drawdown --}}
    <div class="mb-4">
        <div class="flex justify-between text-sm mb-1">
            <span class="text-slate-400">Drawdown</span>
            <span class="{{ $drawdown > ($maxDrawdown * 0.5) ? 'text-rose-400' : 'text-slate-300' }}">{{ number_format($drawdown, 1) }}%</span>
        </div>
        <div class="w-full bg-slate-700/50 rounded-full h-2">
            <div class="h-2 rounded-full transition-all duration-500 {{ $drawdown > ($maxDrawdown * 0.7) ? 'bg-rose-500' : 'bg-blue-500' }}"
                style="width: {{ min(100, ($drawdown / max(1, $maxDrawdown)) * 100) }}%"></div>
        </div>
        <p class="text-xs text-slate-500 mt-1">{{ number_format($drawdown, 1) }}% / {{ $maxDrawdown }}% max</p>
    </div>

    {{-- Open Trades --}}
    <div>
        <div class="flex justify-between text-sm mb-1">
            <span class="text-slate-400">Offene Trades</span>
            <span class="text-white">{{ $openTrades }} / {{ $maxOpenTrades }}</span>
        </div>
        <div class="w-full bg-slate-700/50 rounded-full h-2">
            <div class="h-2 rounded-full bg-blue-500 transition-all duration-500"
                style="width: {{ ($openTrades / max(1, $maxOpenTrades)) * 100 }}%"></div>
        </div>
    </div>
</div>
