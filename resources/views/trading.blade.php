<x-layouts::app :title="__('Trading Dashboard')">
    <div class="min-h-screen bg-[#0a0f1a] -m-6 p-6">
        {{-- Header --}}
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-400 to-amber-600 flex items-center justify-center">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                    </svg>
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-white tracking-tight">Forex Trading Bot</h1>
                    <p class="text-xs text-slate-500">Automatisiertes Trading mit Machine Learning</p>
                </div>
            </div>
            <div class="text-xs text-slate-600">
                {{ now()->format('d.m.Y H:i') }} UTC
            </div>
        </div>

        <livewire:trading.dashboard />
    </div>

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    @endpush
</x-layouts::app>
