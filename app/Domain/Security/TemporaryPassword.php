<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * One-time passwords handed to a user by an administrator (FR-SEC-004,
 * FR-SEC-012). Shown once on screen, never stored or logged in plaintext; the
 * user must replace it at first login.
 */
final class TemporaryPassword
{
    /** No 0/O, 1/l/I: a password read aloud or copied by hand must survive. */
    private const ALPHABET = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function generate(int $length = 16): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= self::ALPHABET[random_int(0, $max)];
        }

        return $password;
    }
}
