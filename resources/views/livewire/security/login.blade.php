<div>
    <h1 class="mb-4 text-lg font-semibold text-ink">Sign in</h1>

    <form wire:submit="login" class="space-y-4" novalidate>
        @error('form')
            <div role="alert" class="alert alert-danger">{{ $message }}</div>
        @enderror

        <div>
            <label for="username" class="label">Username <span class="text-danger" aria-hidden="true">*</span></label>
            <input id="username" type="text" wire:model="username" autocomplete="username" autofocus required class="field">
            @error('username') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="label">Password <span class="text-danger" aria-hidden="true">*</span></label>
            <input id="password" type="password" wire:model="password" autocomplete="current-password" required class="field">
            @error('password') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
        </div>

        <button type="submit" wire:loading.attr="disabled" wire:target="login" class="btn btn-primary w-full">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in…</span>
        </button>
    </form>
</div>
