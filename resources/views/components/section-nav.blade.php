@props(['items'])

<div>
    {{-- Mobile: dropdown (native select) --}}
    <div class="md:hidden flex items-center gap-2">
        <div class="relative flex-1 min-w-0">
            <select x-data
                    x-on:change="if ($event.target.value) window.location.href = $event.target.value"
                    class="block w-full appearance-none bg-surface-700 border border-border-strong rounded-lg pl-4 pr-10 py-2.5 text-sm font-semibold text-text-primary focus:outline-none focus:ring-2 focus:ring-accent-blue focus:border-accent-blue">
                @foreach($items as $item)
                    <option value="{{ $item['href'] }}" {{ !empty($item['active']) ? 'selected' : '' }}>
                        {{ $item['label'] }}@if(!empty($item['badge'])) ({{ $item['badge'] }})@endif
                    </option>
                @endforeach
            </select>
            <svg class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </div>
        @if($slot->isNotEmpty())
            <div class="shrink-0">
                {{ $slot }}
            </div>
        @endif
    </div>

    {{-- Desktop: horizontal tabs --}}
    <div class="hidden md:flex items-end border-b border-border-strong mb-0">
        <div class="flex overflow-x-auto scrollbar-hide">
            @foreach($items as $item)
                <a href="{{ $item['href'] }}"
                   class="shrink-0 px-4 py-2.5 text-sm font-medium border-b-2 transition-colors {{ $item['active'] ? 'border-accent-blue text-text-primary' : 'border-transparent text-text-muted hover:text-text-body hover:border-border-strong' }}">
                    {{ $item['label'] }}
                    @if(!empty($item['badge']))
                        <span class="ml-1.5 px-1.5 py-0.5 text-[10px] font-bold bg-accent-red text-white rounded-full">{{ $item['badge'] }}</span>
                    @endif
                </a>
            @endforeach
        </div>
        @if($slot->isNotEmpty())
            <div class="ml-auto shrink-0 pb-2 pl-3">
                {{ $slot }}
            </div>
        @endif
    </div>
</div>
