<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        @if ($status !== '')
            <p role="status" class="rounded-md border border-success bg-emerald-50 px-3 py-2 text-sm text-success">{{ $status }}</p>
        @endif

        @if ($revealedPassword !== null)
            <div role="alert" class="rounded-md border border-warning bg-amber-50 p-4 text-sm">
                <p class="font-semibold text-ink">Temporary password for {{ $revealedFor }}</p>
                <p class="mt-2 select-all rounded border border-line bg-surface px-3 py-2 font-mono text-base tracking-wide text-ink">{{ $revealedPassword }}</p>
                <p class="mt-2 text-muted">Copy it now. It is shown only once and cannot be looked up later. The user must choose a new password at first sign-in.</p>
                <button type="button" wire:click="dismissPassword" class="mt-3 rounded-md border border-line bg-surface px-3 py-1.5 text-ink hover:bg-canvas">I have copied it</button>
            </div>
        @endif

        <section class="rounded-md border border-line bg-surface">
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
                        <tr wire:key="user-{{ $user->id }}" @class(['bg-blue-50' => $selected?->id === $user->id])>
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
                            <td class="px-4 py-2 text-muted">{{ $user->last_login_at?->timezone('Asia/Manila')->format('M j, Y g:i A') ?? 'Never' }}</td>
                            <td class="px-4 py-2 text-right">
                                <button type="button" wire:click="select({{ $user->id }})" class="rounded-md border border-line px-3 py-1 text-accent hover:bg-canvas">Manage</button>
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

                @error('form') <p role="alert" class="mb-3 rounded-md border border-danger bg-red-50 px-3 py-2 text-sm text-danger">{{ $message }}</p> @enderror

                <fieldset class="mb-3">
                    <legend class="mb-1 text-sm font-medium text-ink">Roles</legend>
                    @foreach ($roles as $role)
                        <label class="flex items-center gap-2 py-0.5 text-sm">
                            <input type="checkbox" wire:model="roleIds" value="{{ $role->id }}" class="rounded border-line">
                            {{ $role->name }}
                        </label>
                    @endforeach
                    @error('role_ids') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                </fieldset>

                <label for="reason" class="mb-1 block text-sm font-medium text-ink">Reason <span class="text-danger" aria-hidden="true">*</span></label>
                <input id="reason" type="text" wire:model="reason" maxlength="500" placeholder="Required for every change; kept in the audit log"
                       class="mb-1 block w-full rounded-md border border-line px-3 py-2 text-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30">
                @error('reason') <p class="mb-2 text-xs text-danger">{{ $message }}</p> @enderror

                <div class="mt-3 flex flex-wrap gap-2 text-sm">
                    <button type="button" wire:click="saveRoles" wire:loading.attr="disabled" class="rounded-md bg-accent px-3 py-1.5 font-semibold text-white hover:bg-blue-700 disabled:opacity-60">Save roles</button>
                    @if ($selected->is_active)
                        <button type="button" wire:click="resetPassword" wire:confirm="Reset the password for {{ $selected->username }}? Their sessions will end." wire:loading.attr="disabled" class="rounded-md border border-line px-3 py-1.5 hover:bg-canvas disabled:opacity-60">Reset password</button>
                        @if ($selected->locked_until?->isFuture() || $selected->failed_attempts > 0)
                            <button type="button" wire:click="unlock" wire:loading.attr="disabled" class="rounded-md border border-line px-3 py-1.5 hover:bg-canvas disabled:opacity-60">Unlock</button>
                        @endif
                        <button type="button" wire:click="deactivate" wire:confirm="Deactivate {{ $selected->username }}? They will be signed out." wire:loading.attr="disabled" class="rounded-md border border-danger px-3 py-1.5 text-danger hover:bg-red-50 disabled:opacity-60">Deactivate</button>
                    @else
                        <button type="button" wire:click="reactivate" wire:loading.attr="disabled" class="rounded-md border border-line px-3 py-1.5 hover:bg-canvas disabled:opacity-60">Reactivate</button>
                    @endif
                </div>
            </section>
        @endif

        <section class="rounded-md border border-line bg-surface p-4" aria-labelledby="create-title">
            <h2 id="create-title" class="mb-3 text-base font-semibold text-ink">New user</h2>
            <form wire:submit="create" class="space-y-3" novalidate>
                <div>
                    <label for="newUsername" class="mb-1 block text-sm font-medium text-ink">Username <span class="text-danger" aria-hidden="true">*</span></label>
                    <input id="newUsername" type="text" wire:model="newUsername" autocomplete="off" required
                           class="block w-full rounded-md border border-line px-3 py-2 text-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30">
                    @error('username') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="newFullName" class="mb-1 block text-sm font-medium text-ink">Full name <span class="text-danger" aria-hidden="true">*</span></label>
                    <input id="newFullName" type="text" wire:model="newFullName" autocomplete="off" required
                           class="block w-full rounded-md border border-line px-3 py-2 text-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30">
                    @error('full_name') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                </div>
                <fieldset>
                    <legend class="mb-1 text-sm font-medium text-ink">Roles <span class="text-danger" aria-hidden="true">*</span></legend>
                    @foreach ($roles as $role)
                        <label class="flex items-center gap-2 py-0.5 text-sm">
                            <input type="checkbox" wire:model="newRoleIds" value="{{ $role->id }}" class="rounded border-line">
                            {{ $role->name }}
                        </label>
                    @endforeach
                </fieldset>
                @unless ($selected)
                    @error('form') <p role="alert" class="text-sm text-danger">{{ $message }}</p> @enderror
                @endunless
                <button type="submit" wire:loading.attr="disabled" wire:target="create" class="w-full rounded-md bg-accent px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60">Create user</button>
            </form>
        </section>
    </div>
</div>
