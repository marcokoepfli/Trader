<div wire:poll.5s class="rounded-2xl bg-gradient-to-br from-slate-800/80 to-slate-900/80 p-6 border border-slate-700/50 backdrop-blur-sm">
    <h3 class="text-lg font-bold text-white mb-4">Live Signale</h3>

    <div class="space-y-2 max-h-[400px] overflow-y-auto custom-scrollbar">
        @forelse($signals as $signal)
            <div class="flex items-center justify-between p-3 rounded-xl {{ $signal->was_executed ? 'bg-emerald-500/10 border border-emerald-500/20' : 'bg-slate-700/30 border border-slate-700/50' }}">
                <div class="flex items-center gap-3">
                    <span class="px-2 py-0.5 text-xs font-bold rounded {{ $signal->direction === 'BUY' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400' }}">
                        {{ $signal->direction }}
                    </span>
                    <div>
                        <span class="text-sm font-semibold text-white">{{ $signal->pair }}</span>
                        <p class="text-xs text-slate-400">{{ Str::limit($signal->strategy, 25) }}</p>
                    </div>
                </div>
                <div class="text-right">
                    <span class="text-sm font-medium {{ $signal->was_executed ? 'text-emerald-400' : 'text-slate-500' }}">
                        {{ number_format($signal->confidence * 100, 0) }}%
                    </span>
                    <p class="text-xs text-slate-500">{{ $signal->created_at->diffForHumans() }}</p>
                    @if(!$signal->was_executed && $signal->rejection_reason)
                        <p class="text-xs text-rose-400/70 mt-0.5">{{ Str::limit($signal->rejection_reason, 40) }}</p>
                    @endif
                </div>
            </div>
        @empty
            <div class="text-center py-8">
                <p class="text-slate-500 text-sm">Noch keine Signale</p>
            </div>
        @endforelse
    </div>
</div>
