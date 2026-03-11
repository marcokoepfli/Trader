<div class="rounded-2xl bg-gradient-to-br from-slate-800/80 to-slate-900/80 p-6 border border-slate-700/50 backdrop-blur-sm">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-bold text-white">Trading Regeln</h3>
        <button wire:click="$toggle('showForm')" class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-amber-500/20 text-amber-400 border border-amber-500/30 hover:bg-amber-500/30 transition-colors">
            {{ $showForm ? 'Abbrechen' : '+ Neue Regel' }}
        </button>
    </div>

    {{-- Neue Regel Form --}}
    @if($showForm)
        <form wire:submit="addRule" class="mb-4 p-4 rounded-xl bg-slate-700/30 border border-slate-600/30 space-y-3">
            <input wire:model="newName" type="text" placeholder="Regelname" class="w-full bg-slate-700/50 border border-slate-600/50 text-white text-sm rounded-lg px-3 py-2 placeholder-slate-500 focus:ring-amber-500 focus:border-amber-500">
            <textarea wire:model="newDescription" placeholder="Beschreibung" rows="2" class="w-full bg-slate-700/50 border border-slate-600/50 text-white text-sm rounded-lg px-3 py-2 placeholder-slate-500 focus:ring-amber-500 focus:border-amber-500"></textarea>
            <input wire:model="newReason" type="text" placeholder="Begründung" class="w-full bg-slate-700/50 border border-slate-600/50 text-white text-sm rounded-lg px-3 py-2 placeholder-slate-500 focus:ring-amber-500 focus:border-amber-500">
            <button type="submit" class="w-full px-4 py-2 bg-amber-500 hover:bg-amber-400 text-white font-bold rounded-lg text-sm transition-colors">Regel erstellen</button>
            @error('newName') <p class="text-rose-400 text-xs">{{ $message }}</p> @enderror
        </form>
    @endif

    <div class="space-y-2 max-h-[400px] overflow-y-auto custom-scrollbar">
        @forelse($rules as $rule)
            <div class="p-3 rounded-xl {{ $rule->active ? 'bg-slate-700/30 border border-slate-700/50' : 'bg-slate-800/30 border border-slate-800/50 opacity-60' }}">
                <div class="flex items-center justify-between mb-1">
                    <div class="flex items-center gap-2">
                        <button wire:click="toggleRule({{ $rule->id }})" class="relative inline-flex h-5 w-9 rounded-full transition-colors {{ $rule->active ? 'bg-emerald-500' : 'bg-slate-600' }}">
                            <span class="inline-block h-4 w-4 rounded-full bg-white transition-transform mt-0.5 {{ $rule->active ? 'translate-x-4' : 'translate-x-0.5' }}"></span>
                        </button>
                        <span class="text-sm font-medium text-white">{{ $rule->name }}</span>
                        <span class="px-1.5 py-0.5 text-xs rounded {{ $rule->source === 'auto' ? 'bg-blue-500/20 text-blue-400' : 'bg-purple-500/20 text-purple-400' }}">
                            {{ strtoupper($rule->source) }}
                        </span>
                    </div>
                    @if($rule->source === 'manual')
                        <button wire:click="deleteRule({{ $rule->id }})" wire:confirm="Regel wirklich löschen?" class="text-slate-500 hover:text-rose-400 transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                    @endif
                </div>
                <p class="text-xs text-slate-400 mb-1">{{ $rule->description }}</p>
                <div class="flex gap-3 text-xs text-slate-500">
                    <span>Verhindert: {{ $rule->trades_prevented }}</span>
                    <span>Ersparnis: ${{ number_format($rule->estimated_savings, 2) }}</span>
                </div>
            </div>
        @empty
            <div class="text-center py-8">
                <p class="text-slate-500 text-sm">Noch keine Regeln</p>
            </div>
        @endforelse
    </div>
</div>
