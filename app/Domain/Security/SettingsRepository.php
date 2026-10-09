<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Models\ApplicationSetting;
use InvalidArgumentException;

/**
 * Reads settings: the value saved in application_settings, else the default in
 * config/bcis.php (FR-SEC-013). Writing goes through an Action that also keeps
 * the change history. Registered as a scoped binding, so each request reads a
 * key from the database at most once.
 */
final class SettingsRepository
{
    /** @var array<string, mixed> */
    private array $cache = [];

    public function get(string $key): mixed
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $defaults = $this->defaults();

        if (! array_key_exists($key, $defaults)) {
            throw new InvalidArgumentException("Unknown setting '{$key}'.");
        }

        $row = ApplicationSetting::query()->find($key);

        return $this->cache[$key] = $row !== null ? $row->value : $defaults[$key];
    }

    public function int(string $key): int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : (int) $this->defaults()[$key];
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * Keys are flat strings with dots in them, so config()'s dot notation
     * cannot be used to look them up one by one.
     *
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        $defaults = config('bcis.setting_defaults');

        return is_array($defaults) ? $defaults : [];
    }
}
