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
<body class="min-h-screen font-sans antialiased">
    {{-- Sign-in and change-password screens: no sidebar, no data. --}}
    <main class="flex min-h-screen items-center justify-center p-6">
        <div class="w-full max-w-sm">
            <div class="mb-6 text-center">
                <div class="text-2xl font-semibold tracking-tight text-primary">BCIS</div>
                <div class="text-sm text-muted">Bukidnon Cable &amp; Internet Services</div>
            </div>
            <div class="rounded-md border border-line bg-surface p-6 shadow-sm">
                {{ $slot }}
            </div>
        </div>
    </main>
    @livewireScripts
</body>
</html>
