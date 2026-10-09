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
        <a href="#main" class="no-print sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-[60] focus:rounded-md focus:bg-surface focus:px-3 focus:py-2 focus:text-sm focus:font-medium focus:shadow-lg">Skip to main content</a>

        {{-- Sidebar shell: stays stable across wire:navigate page changes (PRD 17). --}}
        <aside class="on-dark no-print sticky top-0 hidden h-screen w-60 shrink-0 flex-col overflow-y-auto bg-primary text-white md:flex" aria-label="Main navigation">
            {{-- Same height as the top bar so the two rows line up. --}}
            <div class="flex h-14 shrink-0 flex-col justify-center border-b border-white/10 px-5">
                <span class="text-lg font-semibold leading-tight tracking-tight">BCIS</span>
                <span class="text-xs leading-tight text-white/70">Bukidnon Cable &amp; Internet</span>
            </div>
            <nav class="flex-1 px-3 pb-6 pt-3 text-sm">
                @include('partials.nav-items')
            </nav>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="no-print flex h-14 items-center justify-between gap-3 border-b border-line bg-surface px-4 md:px-6">
                <div class="flex min-w-0 items-center gap-3">
                    {{-- Below md the sidebar is hidden, so the same items open from here. --}}
                    <details class="relative md:hidden">
                        <summary class="btn btn-secondary btn-sm list-none">
                            <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M2 4h12M2 8h12M2 12h12"/></svg>
                            Menu
                        </summary>
                        <nav class="on-dark absolute left-0 top-full z-40 mt-2 max-h-[calc(100vh-5rem)] w-64 overflow-y-auto rounded-md bg-primary p-3 text-sm text-white shadow-lg" aria-label="Main navigation">
                            @include('partials.nav-items')
                        </nav>
                    </details>
                    <h1 class="truncate text-lg font-semibold text-ink">{{ $title }}</h1>
                </div>
                <div class="flex shrink-0 items-center gap-2 text-sm sm:gap-4">
                    <span class="hidden text-muted lg:inline">{{ auth()->user()?->full_name }}</span>
                    <button type="button" data-lock-now class="btn btn-secondary btn-sm">Lock</button>
                    <a href="{{ route('password.change') }}" class="text-muted underline hover:text-ink">Change password</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn btn-secondary btn-sm">Sign out</button>
                    </form>
                </div>
            </header>

            <main id="main" tabindex="-1" class="flex-1 p-4 focus:outline-none md:p-6">
                @if (session('status'))
                    <p role="status" class="alert alert-success mb-4">{{ session('status') }}</p>
                @endif
                {{ $slot }}
            </main>
        </div>
    </div>

    {{-- Lock overlay (FR-UI-008). Sits on top of the untouched page, so unsaved work stays.
         Near-opaque so figures on the page behind cannot be read while locked. --}}
    <div data-lock-overlay hidden class="no-print fixed inset-0 z-50 flex items-center justify-center bg-primary/95 p-6" role="dialog" aria-modal="true" aria-labelledby="lock-title">
        <div class="w-full max-w-sm rounded-md border border-line bg-surface p-6 shadow-lg">
            <h2 id="lock-title" class="mb-1 text-lg font-semibold text-ink">Session locked</h2>
            <p class="mb-4 text-sm text-muted">Enter your password to continue. Your page is kept as you left it.</p>
            @include('partials.unlock-form')
        </div>
    </div>
    @livewireScripts
</body>
</html>
