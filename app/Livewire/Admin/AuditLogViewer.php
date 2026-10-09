<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Domain\Security\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Read-only audit viewer (FR-SEC-009, FR-SEC-010). It has no method that writes:
 * the only actions are "apply filters" and "clear filters". Rows are insert-only
 * in the database too. Filters are untrusted input and are validated before use.
 */
#[Title('Audit Log')]
class AuditLogViewer extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    private const PER_PAGE = 50;

    public string $from = '';

    public string $to = '';

    public string $actorId = '';

    public string $action = '';

    public string $entityType = '';

    public string $requestId = '';

    // Every request, including pagination and $refresh, needs the permission.
    public function boot(): void
    {
        $this->authorize('audit.view');
    }

    public function mount(): void
    {
        $this->authorize('audit.view');
    }

    public function apply(): void
    {
        $this->authorize('audit.view');

        $this->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'actorId' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:80'],
            'entityType' => ['nullable', 'string', 'max:80'],
            'requestId' => ['nullable', 'string', 'max:64'],
        ]);

        $this->resetPage();
    }

    public function clear(): void
    {
        $this->authorize('audit.view');

        $this->reset(['from', 'to', 'actorId', 'action', 'entityType', 'requestId']);
        $this->resetErrorBag();
        $this->resetPage();
    }

    public function render(): mixed
    {
        $query = AuditLog::query()
            ->leftJoin('users', 'users.id', '=', 'audit_logs.actor_user_id')
            ->select('audit_logs.*', 'users.full_name as actor_name', 'users.username as actor_username')
            ->when($this->date($this->from), fn ($q, Carbon $d) => $q->where('audit_logs.occurred_at', '>=', $d->startOfDay()))
            ->when($this->date($this->to), fn ($q, Carbon $d) => $q->where('audit_logs.occurred_at', '<=', $d->endOfDay()))
            // ctype_digit() is false for '' and for anything with a non-digit, so junk is ignored.
            ->when(ctype_digit($this->actorId), fn ($q) => $q->where('audit_logs.actor_user_id', (int) $this->actorId))
            ->when($this->action !== '', fn ($q) => $q->where('audit_logs.action', $this->action))
            ->when($this->entityType !== '', fn ($q) => $q->where('audit_logs.entity_type', $this->entityType))
            ->when($this->requestId !== '', fn ($q) => $q->where('audit_logs.request_id', $this->requestId))
            ->orderByDesc('audit_logs.id');

        // Known events first, then anything else already in the table (later phases add more).
        $known = array_map(fn (AuditAction $a): string => $a->value, AuditAction::cases());
        $actions = collect($known)
            ->merge(AuditLog::query()->select('action')->distinct()->pluck('action'))
            ->unique()->sort()->values()->all();

        return view('livewire.admin.audit-log', [
            'logs' => $query->simplePaginate(self::PER_PAGE),
            'actions' => $actions,
            'actors' => User::query()->orderBy('username')->get(['id', 'username', 'full_name']),
            'entityTypes' => AuditLog::query()->whereNotNull('entity_type')->select('entity_type')->distinct()->orderBy('entity_type')->pluck('entity_type'),
        ]);
    }

    /** A filter date, or null when it is empty or not a valid date. */
    private function date(string $value): ?Carbon
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value, 'Asia/Manila');
        } catch (Throwable) {
            return null;
        }
    }
}
