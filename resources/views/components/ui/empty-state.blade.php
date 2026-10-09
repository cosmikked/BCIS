@props(['title', 'message' => null])
<div {{ $attributes->class(['rounded-md border border-dashed border-line bg-surface px-4 py-8 text-center']) }}>
    <p class="text-sm font-medium text-ink">{{ $title }}</p>
    @if ($message)
        <p class="mx-auto mt-1 max-w-md text-sm text-muted">{{ $message }}</p>
    @endif
    {{ $slot }}
</div>
