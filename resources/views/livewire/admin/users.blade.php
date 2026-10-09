<div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
    <div class="space-y-6">
        @if ($status !== '')
            <p role="status" class="alert alert-success">{{ $status }}</p>
        @endif

        @if ($revealedPassword !== null)
            <div role="alert" class="alert alert-warning p-4">
                <p class="font-semibold text-ink">Temporary password for {{ $revealedFor }}</p>
                <p class="mt-2 select-all rounded border border-line bg-surface px-3 py-2 font-mono text-base tracking-wide text-ink">{{ $revealedPassword }}</p>
                <p class="mt-2 text-ink">Copy it now. It is shown only once and cannot be looked up later. The user must choose a new password at first sign-in.</p>
                <button type="button" wire:click="dismissPassword" class="btn btn-secondary btn-sm mt-3">I have copied it</button>
            </div>
        @endif

        <section class="overflow-x-auto rounded-md border border-line bg-surface">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-line bg-canvas text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th scope="col" class="px-4 py-2">Username</th>
                        <th scope="col" class="px-4 py-2">Name</th>
                        <th scope="col" class="px-4 py-2">Roles</th>
                        <th scope="col" class="px-4 py-2">Status</th>
                        <th scope="col" class="px-4 py-2">Last sign-in</th>
                        <th scope="col" class="px-4 py-2"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($users as $user)
                        <tr wire:key="user-{{ $user->id }}" @class(['bg-selected' => $selected?->id === $user->id])>
                            <td class="px-4 py-2 font-medium text-ink">{{ $user->username }}</td>
                            <td class="px-4 py-2">{{ $user->full_name }}</td>
                            <td class="px-4 py-2 text-muted">{{ $user->roles->pluck('name')->implode(', ') ?: 'None' }}</td>
                            <td class="px-4 py-2">
                                @if (! $user->is_active)
                                    <span class="badge badge-neutral">Inactive</span>
                                @elseif ($user->locked_until?->isFuture())
                                    <span class="badge badge-danger">Locked</span>
                                @elseif ($user->must_change_password)
                                    <span class="badge badge-warning">Temporary password</span>
                                @else
                                    <span class="badge badge-success">Active</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-muted">{{ $user->last_login_at?->timezone('Asia/Manila')->format('M j, Y g:i A') ?? 'Never' }}</td>
                            <td class="px-4 py-2 text-right">
                                <button type="button" wire:click="select({{ $user->id }})" aria-label="Manage {{ $user->username }}" aria-pressed="{{ $selected?->id === $user->id ? 'true' : 'false' }}" class="btn btn-secondary btn-sm">Manage</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    </div>

    <div class="space-y-6">
        @if ($selected)
            <section class="rounded-md border border-line bg-surface p-4" aria-labelledby="manage-title">
                <h2 id="manage-title" class="text-base font-semibold text-ink">{{ $selected->full_name }}</h2>
                <p class="mb-3 text-xs text-muted">{{ $selected->username }}</p>

                @error('form') <p role="alert" class="alert alert-danger mb-3">{{ $message }}</p> @enderror

                <fieldset class="mb-3">
                    <legend class="label">Roles</legend>
                    @foreach ($roles as $role)
                        <label class="flex items-center gap-2 py-0.5 text-sm">
                            <input type="checkbox" wire:model="roleIds" value="{{ $role->id }}">
                            {{ $role->name }}
                        </label>
                    @endforeach
                    @error('role_ids') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                </fieldset>

                <label for="reason" class="label">Reason <span class="text-danger" aria-hidden="true">*</span></label>
                <input id="reason" type="text" wire:model="reason" maxlength="500" placeholder="Required for every change; kept in the audit log" class="field mb-1">
                @error('reason') <p class="mb-2 text-xs text-danger">{{ $message }}</p> @enderror

                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" wire:click="saveRoles" wire:loading.attr="disabled" class="btn btn-primary btn-sm">Save roles</button>
                    @if ($selected->is_active)
                        <button type="button" wire:click="resetPassword" wire:confirm="Reset the password for {{ $selected->username }}? Their sessions will end." wire:loading.attr="disabled" class="btn btn-secondary btn-sm">Reset password</button>
                        @if ($selected->locked_until?->isFuture() || $selected->failed_attempts > 0)
                            <button type="button" wire:click="unlock" wire:loading.attr="disabled" class="btn btn-secondary btn-sm">Unlock</button>
                        @endif
                        <button type="button" wire:click="deactivate" wire:confirm="Deactivate {{ $selected->username }}? They will be signed out." wire:loading.attr="disabled" class="btn btn-danger btn-sm">Deactivate</button>
                    @else
                        <button type="button" wire:click="reactivate" wire:loading.attr="disabled" class="btn btn-secondary btn-sm">Reactivate</button>
                    @endif
                </div>
            </section>
        @endif

        <section class="rounded-md border border-line bg-surface p-4" aria-labelledby="create-title">
            <h2 id="create-title" class="mb-3 text-base font-semibold text-ink">New user</h2>
            <form wire:submit="create" class="space-y-3" novalidate>
                <div>
                    <label for="newUsername" class="label">Username <span class="text-danger" aria-hidden="true">*</span></label>
                    <input id="newUsername" type="text" wire:model="newUsername" autocomplete="off" required class="field">
                    @error('username') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="newFullName" class="label">Full name <span class="text-danger" aria-hidden="true">*</span></label>
                    <input id="newFullName" type="text" wire:model="newFullName" autocomplete="off" required class="field">
                    @error('full_name') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                </div>
                <fieldset>
                    <legend class="label">Roles <span class="text-danger" aria-hidden="true">*</span></legend>
                    @foreach ($roles as $role)
                        <label class="flex items-center gap-2 py-0.5 text-sm">
                            <input type="checkbox" wire:model="newRoleIds" value="{{ $role->id }}">
                            {{ $role->name }}
                        </label>
                    @endforeach
                </fieldset>
                @unless ($selected)
                    @error('form') <p role="alert" class="text-sm text-danger">{{ $message }}</p> @enderror
                @endunless
                <button type="submit" wire:loading.attr="disabled" wire:target="create" class="btn btn-primary w-full">Create user</button>
            </form>
        </section>
    </div>
</div>
