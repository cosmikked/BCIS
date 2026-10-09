<?php

declare(strict_types=1);

namespace App\Domain\Subscribers;

use App\Domain\Subscribers\Enums\ContactType;

/**
 * One canonical form per contact so the same phone number typed as
 * 0917-123-4567 or +63 917 123 4567 is stored, searched and matched the same
 * way (FR-SUB-003, FR-SUB-012).
 */
final class ContactNormalizer
{
    public static function canonical(ContactType $type, string $value): string
    {
        $value = trim($value);

        return match ($type) {
            ContactType::Mobile => self::phoneDigits($value),
            ContactType::Email => mb_strtolower($value),
            ContactType::Other => $value,
        };
    }

    /** Digits only, with a leading country code 63 turned into the local 0. */
    public static function phoneDigits(string $value): string
    {
        $digits = (string) preg_replace('/\D/', '', $value);

        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            return '0'.substr($digits, 2);
        }

        return $digits;
    }

    /** Escapes LIKE wildcards so a typed % or _ matches itself. */
    public static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
