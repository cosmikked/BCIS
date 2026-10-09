<?php

declare(strict_types=1);

namespace App\Domain\Security\Exceptions;

use RuntimeException;

/**
 * A password check failed or the account is locked. The message is safe to show
 * to the person at the keyboard: it never says whether the username exists
 * (FR-SEC-002).
 */
final class LoginFailed extends RuntimeException
{
    private function __construct(string $message, public readonly int $retryAfterSeconds = 0)
    {
        parent::__construct($message);
    }

    public static function invalid(): self
    {
        return new self('Incorrect username or password.');
    }

    public static function lockedOut(int $seconds): self
    {
        $minutes = max(1, (int) ceil($seconds / 60));
        $unit = $minutes === 1 ? 'minute' : 'minutes';

        return new self("Too many failed attempts. Try again in {$minutes} {$unit}.", $seconds);
    }
}
