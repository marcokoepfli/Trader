<div class="rounded-2xl bg-gradient-to-br from-slate-800/80 to-slate-900/80 p-6 border border-slate-700/50 backdrop-blur-sm">
    <h3 class="text-lg font-bold text-white mb-4">Trade Log</h3>

    {{-- Filter --}}
    <div class="grid grid-cols-3 gap-3 mb-4">
        <select wire:model.live="filterPair" class="bg-slate-700/50 border border-slate-600/50 text-white text-sm rounded-xl px-3 py-2 focus:ring-amber-500 focus:border-amber-500">
            <option value="">Alle Paare</option>
            @foreach($pairs as $pair)
                <option value="{{ $pair }}">{{ $pair }}</option>
            @endforeach
        </select>
        <input wire:model.live.debounce.300ms="filterStrategy" type="text" placeholder="Strategie..." class="bg-slate-700/50 border border-slate-600/50 text-white text-sm rounded-xl px-3 py-2 placeholder-slate-500 focus:ring-amber-500 focus:border-amber-500">
        <select wire:model.live="filterResult" class="bg-slate-700/50 border border-slate-600/50 text-white text-sm rounded-xl px-3 py-2 focus:ring-amber-500 focus:border-amber-500">
            <option value="">Alle</option>
            <option value="OPEN">Offen</option>
            <option value="WIN">Win</option>
            <option value="LOSS">Loss</option>
        </select>
    </div>

    {{-- Tabelle --}}
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-700/50">
                    <th wire:click="sort('opened_at')" class="text-left text-xs font-medium text-slate-400 pb-3 cursor-pointer hover:text-white">Datum</th>
                    <th wire:click="sort('pair')" class="text-left text-xs font-medium text-slate-400 pb-3 cursor-pointer hover:text-white">Pair</th>
                    <th class="text-left text-xs font-medium text-slate-400 pb-3">Richtung</th>
                    <th class="text-left text-xs font-medium text-slate-400 pb-3">Strategie</th>
                    <th wire:click="sort('entry_price')" class="text-right text-xs font-medium text-slate-400 pb-3 cursor-pointer hover:text-white">Entry</th>
                    <th class="text-right text-xs font-medium text-slate-400 pb-3">SL</th>
                    <th class="text-right text-xs font-medium text-slate-400 pb-3">TP</th>
                    <th wire:click="sort('pnl')" class="text-right text-xs font-medium text-slate-400 pb-3 cursor-pointer hover:text-white">P&L</th>
                    <th class="text-center text-xs font-medium text-slate-400 pb-3">Result</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-700/30">
                @forelse($trades as $trade)
                    <tr class="hover:bg-slate-700/20 transition-colors">
                        <td class="py-3 text-slate-300 text-xs">{{ $trade->opened_at?->format('d.m H:i') }}</td>
                        <td class="py-3 text-white font-medium">{{ $trade->pair }}</td>
                        <td class="py-3">
                            <span class="px-2 py-0.5 text-xs font-bold rounded {{ $trade->direction === 'BUY' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400' }}">
                                {{ $trade->direction }}
                            </span>
                        </td>
                        <td class="py-3 text-slate-400 text-xs">{{ Str::limit($trade->strategy, 20) }}</td>
                        <td class="py-3 text-right text-slate-300 font-mono text-xs">{{ $trade->entry_price }}</td>
                        <td class="py-3 text-right text-rose-400/70 font-mono text-xs">{{ $trade->stop_loss }}</td>
                        <td class="py-3 text-right text-emerald-400/70 font-mono text-xs">{{ $trade->take_profit }}</td>
                        <td class="py-3 text-right font-mono text-xs font-bold {{ ($trade->pnl ?? 0) >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                            {{ $trade->pnl !== null ? ($trade->pnl >= 0 ? '+' : '') . number_format($trade->pnl, 2) : '—' }}
                        </td>
                        <td class="py-3 text-center">
                            <span class="px-2 py-0.5 text-xs font-bold rounded-full
                                {{ $trade->result === 'WIN' ? 'bg-emerald-500/20 text-emerald-400' : ($trade->result === 'LOSS' ? 'bg-rose-500/20 text-rose-400' : 'bg-blue-500/20 text-blue-400') }}">
                                {{ $trade->result }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center py-8 text-slate-500">Noch keine Trades</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $trades->links() }}
    </div>
</div>
