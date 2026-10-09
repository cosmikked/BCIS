{{-- Lock-screen form. Posted by resources/js/session-lock.js; the server decides. --}}
<form data-unlock-form class="space-y-3" novalidate>
    <div>
        <label for="unlock-password" class="label">Password</label>
        <input id="unlock-password" name="password" type="password" autocomplete="current-password" required class="field">
    </div>
    <p data-unlock-error role="alert" class="hidden text-sm text-danger-ink"></p>
    <button type="submit" class="btn btn-primary w-full">Unlock</button>
</form>
<form method="POST" action="{{ route('logout') }}" class="mt-3 text-center">
    @csrf
    <button type="submit" class="text-sm text-muted underline hover:text-ink">Sign out instead</button>
</form>
