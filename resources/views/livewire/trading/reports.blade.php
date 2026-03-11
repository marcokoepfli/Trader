<div class="rounded-2xl bg-gradient-to-br from-slate-800/80 to-slate-900/80 p-6 border border-slate-700/50 backdrop-blur-sm">
    <h3 class="text-lg font-bold text-white mb-4">Reports</h3>

    @if($reports->isEmpty())
        <div class="text-center py-12">
            <svg class="w-12 h-12 text-slate-600 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <p class="text-slate-500 text-sm">Noch keine Reports vorhanden</p>
            <p class="text-slate-600 text-xs mt-1">Der erste Report wird heute um 23:55 generiert</p>
        </div>
    @else
        <div class="space-y-3">
            @foreach($reports as $report)
                <div wire:key="report-{{ $report->id }}"
                     wire:click="selectReport({{ $report->id }})"
                     class="cursor-pointer rounded-xl border transition-all duration-200
                        {{ $selectedReportId === $report->id
                            ? 'bg-slate-700/40 border-amber-500/40'
                            : 'bg-slate-700/20 border-slate-700/40 hover:border-slate-600/60' }}">

                    {{-- Report Header --}}
                    <div class="p-4 flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="w-10 h-10 rounded-lg flex items-center justify-center
                                {{ $report->daily_pnl >= 0 ? 'bg-emerald-500/15' : 'bg-rose-500/15' }}">
                                <span class="text-lg {{ $report->daily_pnl >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                                    {{ $report->daily_pnl >= 0 ? '↑' : '↓' }}
                                </span>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-white">{{ $report->report_date->format('d.m.Y') }}</p>
                                <p class="text-xs text-slate-500">{{ $report->trades_closed }} Trades</p>
                            </div>
                        </div>

                        <div class="flex items-center gap-6">
                            <div class="text-right">
                                <p class="text-sm font-bold {{ $report->daily_pnl >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                                    {{ $report->daily_pnl >= 0 ? '+' : '' }}${{ number_format($report->daily_pnl, 2) }}
                                </p>
                                <p class="text-xs text-slate-500">
                                    {{ $report->daily_pnl_pct >= 0 ? '+' : '' }}{{ number_format($report->daily_pnl_pct, 2) }}%
                                </p>
                            </div>

                            <div class="flex gap-2">
                                <span class="px-2 py-0.5 text-xs rounded-full bg-emerald-500/15 text-emerald-400">{{ $report->wins }}W</span>
                                <span class="px-2 py-0.5 text-xs rounded-full bg-rose-500/15 text-rose-400">{{ $report->losses }}L</span>
                            </div>

                            <svg class="w-4 h-4 text-slate-500 transition-transform {{ $selectedReportId === $report->id ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </div>
                    </div>

                    {{-- Report Detail (aufklappbar) --}}
                    @if($selectedReportId === $report->id)
                        <div class="border-t border-slate-700/50 p-4 space-y-4">
                            {{-- Balance --}}
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                <div class="rounded-lg bg-slate-800/50 p-3">
                                    <p class="text-xs text-slate-500">Start Balance</p>
                                    <p class="text-sm font-semibold text-white">${{ number_format($report->starting_balance, 2) }}</p>
                                </div>
                                <div class="rounded-lg bg-slate-800/50 p-3">
                                    <p class="text-xs text-slate-500">End Balance</p>
                                    <p class="text-sm font-semibold text-white">${{ number_format($report->ending_balance, 2) }}</p>
                                </div>
                                <div class="rounded-lg bg-slate-800/50 p-3">
                                    <p class="text-xs text-slate-500">Trades geöffnet</p>
                                    <p class="text-sm font-semibold text-white">{{ $report->trades_opened }}</p>
                                </div>
                                <div class="rounded-lg bg-slate-800/50 p-3">
                                    <p class="text-xs text-slate-500">Win Rate</p>
                                    <p class="text-sm font-semibold {{ ($report->wins + $report->losses) > 0 && ($report->wins / ($report->wins + $report->losses)) >= 0.5 ? 'text-emerald-400' : 'text-rose-400' }}">
                                        {{ ($report->wins + $report->losses) > 0 ? number_format(($report->wins / ($report->wins + $report->losses)) * 100, 0) : 0 }}%
                                    </p>
                                </div>
                            </div>

                            {{-- Strategy Breakdown --}}
                            @if(!empty($report->strategy_breakdown))
                                <div>
                                    <p class="text-xs font-semibold text-slate-400 mb-2 uppercase tracking-wider">Strategien</p>
                                    <div class="space-y-2">
                                        @foreach($report->strategy_breakdown as $strategy => $data)
                                            <div class="flex items-center justify-between p-2 rounded-lg bg-slate-800/30">
                                                <div class="flex items-center gap-2">
                                                    <span class="text-sm text-white">{{ $strategy }}</span>
                                                    @if($data['on_cooldown'] ?? false)
                                                        <span class="px-1.5 py-0.5 text-xs rounded bg-amber-500/20 text-amber-400">Cooldown</span>
                                                    @endif
                                                </div>
                                                <div class="flex items-center gap-4 text-xs">
                                                    <span class="text-slate-400">Score: <span class="text-white font-medium">{{ number_format($data['score'] ?? 0, 2) }}</span></span>
                                                    <span class="text-slate-400">WR: <span class="text-white font-medium">{{ number_format($data['win_rate'] ?? 0, 0) }}%</span></span>
                                                    <span class="text-slate-400">Trades: <span class="text-white font-medium">{{ $data['trades'] ?? 0 }}</span></span>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- Pair Breakdown --}}
                            @if(!empty($report->pair_breakdown))
                                <div>
                                    <p class="text-xs font-semibold text-slate-400 mb-2 uppercase tracking-wider">Währungspaare</p>
                                    <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                                        @foreach($report->pair_breakdown as $pair => $data)
                                            <div class="flex items-center justify-between p-2 rounded-lg bg-slate-800/30">
                                                <span class="text-sm font-medium text-white">{{ $pair }}</span>
                                                <div class="text-right">
                                                    <p class="text-xs font-semibold {{ ($data['pnl'] ?? 0) >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                                                        {{ ($data['pnl'] ?? 0) >= 0 ? '+' : '' }}${{ number_format($data['pnl'] ?? 0, 2) }}
                                                    </p>
                                                    <p class="text-xs text-slate-500">{{ $data['trades'] ?? 0 }}T / {{ $data['wins'] ?? 0 }}W</p>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- Neue Regeln --}}
                            @if(!empty($report->new_rules))
                                <div>
                                    <p class="text-xs font-semibold text-slate-400 mb-2 uppercase tracking-wider">Neue Regeln</p>
                                    <div class="space-y-1">
                                        @foreach($report->new_rules as $rule)
                                            <div class="flex items-center gap-2 p-2 rounded-lg bg-blue-500/10 border border-blue-500/20">
                                                <svg class="w-3.5 h-3.5 text-blue-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                </svg>
                                                <span class="text-xs text-blue-300">{{ $rule }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-4">
            {{ $reports->links() }}
        </div>
    @endif
</div>
