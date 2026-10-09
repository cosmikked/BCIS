<div>
    <h1 class="mb-4 text-lg font-semibold text-ink">Sign in</h1>

    <form wire:submit="login" class="space-y-4" novalidate>
        @error('form')
            <div role="alert" class="rounded-md border border-danger bg-red-50 px-3 py-2 text-sm text-danger">{{ $message }}</div>
        @enderror

        <div>
            <label for="username" class="mb-1 block text-sm font-medium text-ink">Username <span class="text-danger" aria-hidden="true">*</span></label>
            <input id="username" type="text" wire:model="username" autocomplete="username" autofocus required
                   class="block w-full rounded-md border border-line px-3 py-2 text-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30">
            @error('username') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="mb-1 block text-sm font-medium text-ink">Password <span class="text-danger" aria-hidden="true">*</span></label>
            <input id="password" type="password" wire:model="password" autocomplete="current-password" required
                   class="block w-full rounded-md border border-line px-3 py-2 text-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30">
            @error('password') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
        </div>

        <button type="submit" wire:loading.attr="disabled" wire:target="login"
                class="w-full rounded-md bg-accent px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in…</span>
        </button>
    </form>
</div>
