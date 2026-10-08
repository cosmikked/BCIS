@props(['title' => 'Print'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
    <style>
        @page { size: A4; margin: 12mm; }
        body { background: #fff; }
    </style>
</head>
<body class="bg-white font-sans text-ink antialiased">
    {{-- Print layout skeleton: no sidebar, no navigation, no scripts. Receipt,
         invoice and report views (later phases) extend this. --}}
    <div class="mx-auto max-w-3xl p-6">
        <header class="mb-4 border-b border-line pb-3">
            <div class="text-lg font-semibold text-primary">{{ config('app.name') }}</div>
            <div class="text-xs text-muted">Bukidnon Cable and Internet Services</div>
        </header>

        <main>
            {{ $slot }}
        </main>

        <footer class="mt-6 border-t border-line pt-2 text-xs text-muted">
            Printed {{ now()->format('Y-m-d H:i') }} (Asia/Manila)
        </footer>
    </div>
</body>
</html>
