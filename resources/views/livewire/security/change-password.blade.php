<div>
    <h1 class="mb-1 text-lg font-semibold text-ink">Change password</h1>
    @if ($forced)
        <p class="mb-4 rounded-md border border-warning bg-amber-50 px-3 py-2 text-sm text-warning" role="status">
            You are using a temporary password. Choose a new one to continue.
        </p>
    @else
        <p class="mb-4 text-sm text-muted">Other devices signed in as you will be signed out.</p>
    @endif

    <form wire:submit="save" class="space-y-4" novalidate>
        <div>
            <label for="current_password" class="mb-1 block text-sm font-medium text-ink">Current password <span class="text-danger" aria-hidden="true">*</span></label>
            <input id="current_password" type="password" wire:model="current_password" autocomplete="current-password" required
                   class="block w-full rounded-md border border-line px-3 py-2 text-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30">
            @error('current_password') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="new_password" class="mb-1 block text-sm font-medium text-ink">New password <span class="text-danger" aria-hidden="true">*</span></label>
            <input id="new_password" type="password" wire:model="new_password" autocomplete="new-password" required
                   class="block w-full rounded-md border border-line px-3 py-2 text-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30">
            <p class="mt-1 text-xs text-muted">At least {{ $minLength }} characters. It must not contain your username.</p>
            @error('new_password') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="new_password_confirmation" class="mb-1 block text-sm font-medium text-ink">Confirm new password <span class="text-danger" aria-hidden="true">*</span></label>
            <input id="new_password_confirmation" type="password" wire:model="new_password_confirmation" autocomplete="new-password" required
                   class="block w-full rounded-md border border-line px-3 py-2 text-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30">
        </div>

        <button type="submit" wire:loading.attr="disabled" wire:target="save"
                class="w-full rounded-md bg-accent px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60">
            <span wire:loading.remove wire:target="save">Change password</span>
            <span wire:loading wire:target="save">Saving…</span>
        </button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">
        @csrf
        <button type="submit" class="text-sm text-muted underline hover:text-ink">Sign out</button>
    </form>
</div>
