<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * Staff login. Never deleted (INV-19): is_active switches an account off and
 * the row stays so history keeps showing the person's name.
 *
 * @property int $id
 * @property string $username
 * @property string $full_name
 * @property string $password_hash
 * @property bool $is_active
 * @property bool $must_change_password
 * @property int $failed_attempts
 * @property Carbon|null $locked_until
 * @property Carbon|null $last_login_at
 * @property Carbon|null $password_changed_at
 */
class User extends Authenticatable
{
    protected $table = 'users';

    // Fillable lists profile fields only. Security columns (password_hash,
    // is_active, lockout counters) are set explicitly by Actions, never by
    // mass assignment from request input.
    protected $fillable = ['username', 'full_name'];

    protected $hidden = ['password_hash'];

    /** @var list<string>|null Memoized for the life of this instance. */
    private ?array $permissionCodes = null;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    // The column is password_hash (ERD plan), not Laravel's default "password".
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    // No "remember me": sessions end on logout or idle timeout (FR-SEC-003).
    public function getRememberTokenName(): string
    {
        return '';
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->withPivot(['assigned_by', 'assigned_at']);
    }

    /**
     * True when an ACTIVE user holds the permission through any of their roles.
     * Code asks this question, never "is this user an Owner?" (INV-17).
     */
    public function hasPermission(string $code): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return in_array($code, $this->permissionCodes(), true);
    }

    /** @return list<string> */
    public function permissionCodes(): array
    {
        return $this->permissionCodes ??= Permission::query()
            ->whereHas('roles.users', fn ($q) => $q->whereKey($this->getKey()))
            ->pluck('code')
            ->all();
    }

    /** Call after changing this user's roles within the same request. */
    public function forgetPermissionCache(): void
    {
        $this->permissionCodes = null;
    }
}
