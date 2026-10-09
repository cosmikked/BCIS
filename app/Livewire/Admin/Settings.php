<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Domain\Security\Actions\UpdateSetting;
use App\Domain\Security\SettingsRepository;
use App\Models\ApplicationSettingChange;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Settings with change history (FR-SEC-013). The list of editable settings and
 * their allowed ranges come from config/bcis.php; the Action enforces them.
 */
#[Title('Settings')]
class Settings extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public ?string $selectedKey = null;

    #[Locked]
    public string $status = '';

    public string $value = '';

    public string $reason = '';

    // Runs on every request (page load, action, $refresh, polling), so a page
    // left open cannot keep reading settings after the permission is gone.
    public function boot(): void
    {
        $this->authorize('settings.manage');
    }

    public function mount(): void
    {
        $this->authorize('settings.manage');
    }

    public function select(string $key, SettingsRepository $settings): void
    {
        $this->authorize('settings.manage');
        abort_unless(array_key_exists($key, $this->rules()), 404);

        $this->selectedKey = $key;
        $this->value = (string) $settings->get($key);
        $this->reason = '';
        $this->status = '';
        $this->resetErrorBag();
    }

    public function save(UpdateSetting $update): void
    {
        $this->authorize('settings.manage');
        abort_if($this->selectedKey === null, 422, 'Choose a setting first.');

        $actor = auth()->user();
        abort_unless($actor instanceof User, 401);

        $update->handle($actor, $this->selectedKey, $this->value, $this->reason);

        $this->status = 'Setting saved.';
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function render(SettingsRepository $settings): mixed
    {
        $rows = [];
        foreach ($this->rules() as $key => $rule) {
            $rows[] = [
                'key' => $key,
                'label' => $rule['label'],
                'unit' => $rule['unit'],
                'min' => $rule['min'],
                'max' => $rule['max'],
                'value' => $settings->get($key),
                'default' => config('bcis.setting_defaults')[$key] ?? null,
            ];
        }

        return view('livewire.admin.settings', [
            'rows' => $rows,
            'selected' => collect($rows)->firstWhere('key', $this->selectedKey),
            'history' => ApplicationSettingChange::query()
                ->leftJoin('users', 'users.id', '=', 'application_setting_changes.changed_by')
                ->select('application_setting_changes.*', 'users.full_name as changed_by_name', 'users.username as changed_by_username')
                ->when($this->selectedKey !== null, fn ($q) => $q->where('application_setting_changes.key', $this->selectedKey))
                ->orderByDesc('application_setting_changes.id')
                ->limit(50)
                ->get(),
        ]);
    }

    /** @return array<string, array{label: string, unit: string, min: int, max: int}> */
    private function rules(): array
    {
        $rules = config('bcis.setting_rules');

        return is_array($rules) ? $rules : [];
    }
}
