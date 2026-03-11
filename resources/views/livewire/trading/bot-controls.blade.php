<div class="rounded-2xl bg-gradient-to-br from-slate-800/80 to-slate-900/80 p-6 border border-slate-700/50 backdrop-blur-sm">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-bold text-white">Bot Control</h3>
        <div class="flex items-center gap-2">
            @if($environment === 'live')
                <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-rose-500/20 text-rose-400 border border-rose-500/30 animate-pulse">LIVE</span>
            @else
                <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">DEMO</span>
            @endif
        </div>
    </div>

    {{-- Status --}}
    <div class="mb-5">
        <div class="flex items-center gap-2 mb-2">
            @if($isRunning && !$isPaused)
                <span class="relative flex h-3 w-3"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex rounded-full h-3 w-3 bg-emerald-500"></span></span>
                <span class="text-emerald-400 font-semibold text-sm">Running</span>
            @elseif($isPaused)
                <span class="h-3 w-3 rounded-full bg-amber-400"></span>
                <span class="text-amber-400 font-semibold text-sm">Paused</span>
                @if($pauseReason)
                    <span class="text-xs text-slate-500 ml-1">— {{ $pauseReason }}</span>
                @endif
            @else
                <span class="h-3 w-3 rounded-full bg-slate-500"></span>
                <span class="text-slate-400 font-semibold text-sm">Stopped</span>
            @endif
        </div>
        @if($startedAt)
            <p class="text-xs text-slate-500">Seit {{ \Carbon\Carbon::parse($startedAt)->diffForHumans() }}</p>
        @endif
    </div>

    {{-- Balance --}}
    <div class="grid grid-cols-2 gap-3 mb-5">
        <div class="rounded-xl bg-slate-700/30 p-3">
            <p class="text-xs text-slate-400 mb-1">Balance</p>
            <p class="text-lg font-bold text-white">${{ number_format($balance, 2) }}</p>
        </div>
        <div class="rounded-xl bg-slate-700/30 p-3">
            <p class="text-xs text-slate-400 mb-1">Unrealisiert</p>
            <p class="text-lg font-bold {{ $unrealizedPnl >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                {{ $unrealizedPnl >= 0 ? '+' : '' }}${{ number_format($unrealizedPnl, 2) }}
            </p>
        </div>
    </div>

    {{-- Buttons --}}
    <div class="flex gap-2">
        @if(!$isRunning)
            <button wire:click="startBot" class="flex-1 px-4 py-2.5 bg-emerald-500 hover:bg-emerald-400 text-white font-bold rounded-xl text-sm transition-all duration-200 shadow-lg shadow-emerald-500/20">
                Start
            </button>
        @else
            @if(!$isPaused)
                <button wire:click="pauseBot" class="flex-1 px-4 py-2.5 bg-amber-500 hover:bg-amber-400 text-white font-bold rounded-xl text-sm transition-all duration-200">
                    Pause
                </button>
            @else
                <button wire:click="resumeBot" class="flex-1 px-4 py-2.5 bg-blue-500 hover:bg-blue-400 text-white font-bold rounded-xl text-sm transition-all duration-200">
                    Resume
                </button>
            @endif
            <button wire:click="stopBot" class="flex-1 px-4 py-2.5 bg-rose-500 hover:bg-rose-400 text-white font-bold rounded-xl text-sm transition-all duration-200 shadow-lg shadow-rose-500/20">
                Stop
            </button>
        @endif
    </div>
</div>
