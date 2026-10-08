@props(['title' => 'BCIS'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen font-sans antialiased">
    <div class="flex min-h-screen">
        {{-- Sidebar shell: stays stable across wire:navigate page changes (PRD 17). --}}
        <aside class="no-print sticky top-0 hidden h-screen w-60 shrink-0 flex-col overflow-y-auto bg-primary text-white md:flex" aria-label="Main navigation">
            <div class="px-5 py-4 text-lg font-semibold tracking-tight">
                BCIS
                <span class="block text-xs font-normal text-white/60">Bukidnon Cable &amp; Internet</span>
            </div>
            <nav class="flex-1 px-3 pb-6 text-sm">
                @foreach (config('navigation') as $item)
                    @php
                        $active = isset($item['route']) && request()->routeIs($item['route']);
                    @endphp
                    <div class="mt-1">
                        @if (isset($item['route']))
                            <a href="{{ route($item['route']) }}" wire:navigate
                               @class(['block rounded-md px-3 py-2', 'bg-white/15 font-semibold' => $active, 'hover:bg-white/10' => ! $active])
                               @if ($active) aria-current="page" @endif>{{ $item['label'] }}</a>
                        @else
                            <span class="block cursor-default px-3 py-2 {{ isset($item['children']) ? 'text-white/70 font-medium' : 'text-white/50' }}">{{ $item['label'] }}</span>
                        @endif

                        @foreach ($item['children'] ?? [] as $child)
                            <span class="block cursor-default rounded-md py-1.5 pl-6 pr-3 text-white/50">{{ $child['label'] }}</span>
                        @endforeach
                    </div>
                @endforeach
            </nav>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            {{-- Top bar placeholder: user menu, lock screen and search arrive in later phases. --}}
            <header class="no-print flex h-14 items-center justify-between border-b border-line bg-surface px-6">
                <h1 class="text-base font-semibold text-ink">{{ $title }}</h1>
                <div class="text-sm text-muted">Not signed in</div>
            </header>

            <main class="flex-1 p-6">
                {{ $slot }}
            </main>
        </div>
    </div>
    @livewireScripts
</body>
</html>
