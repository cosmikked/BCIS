{{-- Lock-screen form. Posted by resources/js/session-lock.js; the server decides. --}}
<form data-unlock-form class="space-y-3" novalidate>
    <div>
        <label for="unlock-password" class="mb-1 block text-sm font-medium text-ink">Password</label>
        <input id="unlock-password" name="password" type="password" autocomplete="current-password" required
               class="block w-full rounded-md border border-line px-3 py-2 text-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30">
    </div>
    <p data-unlock-error role="alert" class="hidden text-sm text-danger"></p>
    <button type="submit"
            class="w-full rounded-md bg-accent px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60">Unlock</button>
</form>
<form method="POST" action="{{ route('logout') }}" class="mt-3 text-center">
    @csrf
    <button type="submit" class="text-sm text-muted underline hover:text-ink">Sign out instead</button>
</form>
