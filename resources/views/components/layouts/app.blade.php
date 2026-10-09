@props(['title' => 'BCIS'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen font-sans antialiased"
      data-session-urls
      data-heartbeat-url="{{ route('session.heartbeat') }}"
      data-lock-url="{{ route('session.lock') }}"
      data-unlock-url="{{ route('session.unlock') }}"
      data-login-url="{{ route('login') }}">
    <div class="flex min-h-screen" data-app-shell>
        {{-- Sidebar shell: stays stable across wire:navigate page changes (PRD 17). --}}
        <aside class="no-print sticky top-0 hidden h-screen w-60 shrink-0 flex-col overflow-y-auto bg-primary text-white md:flex" aria-label="Main navigation">
            <div class="px-5 py-4 text-lg font-semibold tracking-tight">
                BCIS
                <span class="block text-xs font-normal text-white/60">Bukidnon Cable &amp; Internet</span>
            </div>
            <nav class="flex-1 px-3 pb-6 text-sm">
                @foreach (\App\Domain\Security\NavigationMenu::visibleFor(auth()->user(), config('navigation')) as $item)
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
            </nav>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="no-print flex h-14 items-center justify-between border-b border-line bg-surface px-6">
                <h1 class="text-base font-semibold text-ink">{{ $title }}</h1>
                <div class="flex items-center gap-4 text-sm">
                    <span class="text-muted">{{ auth()->user()?->full_name }}</span>
                    <button type="button" data-lock-now class="rounded-md border border-line px-3 py-1.5 text-ink hover:bg-canvas">Lock</button>
                    <a href="{{ route('password.change') }}" class="text-muted underline hover:text-ink">Change password</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="rounded-md border border-line px-3 py-1.5 text-ink hover:bg-canvas">Sign out</button>
                    </form>
                </div>
            </header>

            <main class="flex-1 p-6">
                @if (session('status'))
                    <p role="status" class="mb-4 rounded-md border border-success bg-emerald-50 px-3 py-2 text-sm text-success">{{ session('status') }}</p>
                @endif
                {{ $slot }}
            </main>
        </div>
    </div>

    {{-- Lock overlay (FR-UI-008). Sits on top of the untouched page, so unsaved work stays. --}}
    <div data-lock-overlay hidden class="no-print fixed inset-0 z-50 flex items-center justify-center bg-primary/80 p-6" role="dialog" aria-modal="true" aria-labelledby="lock-title">
        <div class="w-full max-w-sm rounded-md border border-line bg-surface p-6 shadow-lg">
            <h2 id="lock-title" class="mb-1 text-lg font-semibold text-ink">Session locked</h2>
            <p class="mb-4 text-sm text-muted">Enter your password to continue. Your page is kept as you left it.</p>
            @include('partials.unlock-form')
        </div>
    </div>
    @livewireScripts
</body>
</html>
