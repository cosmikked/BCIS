{{-- Sidebar items, shared by the desktop sidebar and the narrow-window menu. --}}
@foreach (\App\Domain\Security\NavigationMenu::visibleFor(auth()->user(), config('navigation')) as $item)
    @php
        $active = isset($item['route']) && request()->routeIs($item['route']);
    @endphp
    <div class="{{ isset($item['children']) ? 'mt-4' : 'mt-1' }} first:mt-0">
        @if (isset($item['route']))
            <a href="{{ route($item['route']) }}" wire:navigate
               @class(['block rounded-md px-3 py-2', 'bg-white/15 font-semibold' => $active, 'hover:bg-white/10' => ! $active])
               @if ($active) aria-current="page" @endif>{{ $item['label'] }}</a>
        @else
            <span class="block cursor-default px-3 py-2 {{ isset($item['children']) ? 'text-white/70 font-medium' : 'text-white/50' }}">{{ $item['label'] }}</span>
        @endif

        @foreach ($item['children'] ?? [] as $child)
            @if (isset($child['route']))
                @php($childActive = request()->routeIs($child['route']))
                <a href="{{ route($child['route']) }}" wire:navigate
                   @class(['block rounded-md py-1.5 pl-6 pr-3', 'bg-white/15 font-semibold' => $childActive, 'text-white/80 hover:bg-white/10' => ! $childActive])
                   @if ($childActive) aria-current="page" @endif>{{ $child['label'] }}</a>
            @else
                <span class="block cursor-default rounded-md py-1.5 pl-6 pr-3 text-white/50">{{ $child['label'] }}</span>
            @endif
        @endforeach
    </div>
@endforeach
