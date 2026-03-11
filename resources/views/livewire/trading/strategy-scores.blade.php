<div class="rounded-2xl bg-gradient-to-br from-slate-800/80 to-slate-900/80 p-6 border border-slate-700/50 backdrop-blur-sm">
    <h3 class="text-lg font-bold text-white mb-4">Strategie Performance</h3>

    <div class="space-y-3">
        @forelse($scores as $score)
            <div class="p-4 rounded-xl bg-slate-700/20 border border-slate-700/40 hover:border-slate-600/60 transition-colors">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-bold text-white">{{ $score->strategy }}</span>
                        @if($score->on_cooldown)
                            <span class="px-2 py-0.5 text-xs rounded-full bg-amber-500/20 text-amber-400 border border-amber-500/30">Cooldown</span>
                        @endif
                    </div>
                    <div class="px-3 py-1 rounded-full text-xs font-bold
                        {{ $score->score >= 0.6 ? 'bg-emerald-500/20 text-emerald-400' : ($score->score >= 0.3 ? 'bg-amber-500/20 text-amber-400' : 'bg-rose-500/20 text-rose-400') }}">
                        Score: {{ number_format($score->score, 2) }}
                    </div>
                </div>

                {{-- Score Bar --}}
                <div class="w-full bg-slate-700/50 rounded-full h-1.5 mb-3">
                    <div class="h-1.5 rounded-full transition-all duration-500
                        {{ $score->score >= 0.6 ? 'bg-emerald-500' : ($score->score >= 0.3 ? 'bg-amber-500' : 'bg-rose-500') }}"
                        style="width: {{ $score->score * 100 }}%"></div>
                </div>

                <div class="grid grid-cols-4 gap-2 text-center">
                    <div>
                        <p class="text-xs text-slate-500">Win Rate</p>
                        <p class="text-sm font-semibold text-white">{{ number_format($score->win_rate, 0) }}%</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500">PF</p>
                        <p class="text-sm font-semibold text-white">{{ number_format($score->profit_factor, 2) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500">Trades</p>
                        <p class="text-sm font-semibold text-white">{{ $score->total_trades }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500">Avg P&L</p>
                        <p class="text-sm font-semibold {{ $score->avg_pnl >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">${{ number_format($score->avg_pnl, 2) }}</p>
                    </div>
                </div>
            </div>
        @empty
            <div class="text-center py-8">
                <p class="text-slate-500 text-sm">Noch keine Strategie-Daten</p>
            </div>
        @endforelse
    </div>
</div>
