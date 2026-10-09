<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Domain\Security\Reason;
use App\Domain\Security\SettingsRepository;
use App\Models\ApplicationSetting;
use App\Models\ApplicationSettingChange;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Changes one setting (FR-SEC-013). Writes the new value, one history row with
 * old value, new value, who and when, and one audit row with the reason, all in
 * one transaction. Only keys listed in config('bcis.setting_rules') can change.
 */
final class UpdateSetting
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SettingsRepository $settings,
    ) {}

    /** @throws ValidationException */
    public function handle(User $actor, string $key, string|int $value, string $reason): void
    {
        Gate::forUser($actor)->authorize('settings.manage');
        $reason = Reason::require($reason);

        $rules = config('bcis.setting_rules');
        $rule = is_array($rules) ? ($rules[$key] ?? null) : null;

        if (! is_array($rule)) {
            throw ValidationException::withMessages(['form' => 'That setting cannot be changed here.']);
        }

        $number = $this->wholeNumber($value);
        if ($number === null || $number < $rule['min'] || $number > $rule['max']) {
            throw ValidationException::withMessages([
                'value' => "Enter a whole number from {$rule['min']} to {$rule['max']} ({$rule['unit']}).",
            ]);
        }

        try {
            DB::transaction(function () use ($actor, $key, $number, $reason): void {
                $row = ApplicationSetting::query()->lockForUpdate()->find($key);
                $old = $row !== null ? $row->value : $this->settings->get($key);

                if ($old === $number) {
                    throw ValidationException::withMessages(['value' => 'That is already the current value.']);
                }

                if ($row === null) {
                    ApplicationSetting::query()->create(['key' => $key, 'value' => $number, 'updated_by' => $actor->id]);
                } else {
                    $row->forceFill(['value' => $number, 'updated_by' => $actor->id, 'updated_at' => now()])->save();
                }

                ApplicationSettingChange::query()->create([
                    'key' => $key,
                    'old_value' => $old,
                    'new_value' => $number,
                    'changed_by' => $actor->id,
                ]);

                $this->audit->record(AuditAction::SettingChanged, $actor, 'application_setting', null, $reason,
                    oldValues: [$key => $old],
                    newValues: [$key => $number],
                );
            });
        } catch (UniqueConstraintViolationException) {
            // Two Owners saved a never-before-set key at the same moment.
            throw ValidationException::withMessages(['form' => 'Someone else changed this setting at the same time. Reload and try again.']);
        }

        $this->settings->flush();
    }

    private function wholeNumber(string|int $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return preg_match('/^\s*\d{1,6}\s*$/', $value) === 1 ? (int) trim($value) : null;
    }
}
