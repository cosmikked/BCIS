<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Session locked · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{-- Served instead of any page while the session is locked, so no data is sent. --}}
<body class="min-h-screen font-sans antialiased"
      data-session-urls
      data-heartbeat-url="{{ route('session.heartbeat') }}"
      data-lock-url="{{ route('session.lock') }}"
      data-unlock-url="{{ route('session.unlock') }}"
      data-login-url="{{ route('login') }}"
      data-start-locked="1"
      data-reload-on-unlock="1">
    <main class="flex min-h-screen items-center justify-center p-6">
        <div class="w-full max-w-sm rounded-md border border-line bg-surface p-6 shadow-sm">
            <h1 class="mb-1 text-lg font-semibold text-ink">Session locked</h1>
            <p class="mb-4 text-sm text-muted">Enter your password to continue.</p>
            @include('partials.unlock-form')
        </div>
    </main>
</body>
</html>
