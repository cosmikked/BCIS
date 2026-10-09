<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Money\Money;
use App\Models\ServiceType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Validates the plan form (FR-SUB-005). Prices arrive as peso text from the form
 * ("1,499.00") and leave as Money, so no float ever touches an amount (INV-01).
 *
 * Internet needs a speed, Cable needs a channel count, Combo needs both. The
 * PRD names the fields but not this rule, so it is flagged in the commit.
 *
 * @phpstan-type Clean array{name: string, service_type_id: int, price: Money, installation_fee: Money, speed_mbps: ?int, channel_count: ?int, description: ?string}
 */
final class PlanInput
{
    /**
     * @param  array<string, mixed>  $input
     * @return Clean
     *
     * @throws ValidationException
     */
    public static function validate(array $input): array
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'service_type_id' => ['required', 'integer', Rule::exists('service_types', 'id')],
            'price' => ['required', 'string'],
            'installation_fee' => ['nullable', 'string'],
            'speed_mbps' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'channel_count' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'service_type_id' => 'service type',
            'speed_mbps' => 'speed',
            'channel_count' => 'channel count',
        ])->validate();

        $price = self::money($data['price'], 'price');
        if (! $price->isPositive()) {
            throw ValidationException::withMessages(['price' => 'The price must be more than zero.']);
        }

        $fee = self::money($data['installation_fee'] ?? '0', 'installation_fee');
        if ($fee->isNegative()) {
            throw ValidationException::withMessages(['installation_fee' => 'The installation fee cannot be negative.']);
        }

        $type = ServiceType::query()->findOrFail((int) $data['service_type_id']);
        // A blank form field arrives as '', which must mean "not given", not 0.
        $speed = self::wholeNumberOrNull($data['speed_mbps'] ?? null);
        $channels = self::wholeNumberOrNull($data['channel_count'] ?? null);

        $needsSpeed = in_array($type->code, ['INTERNET', 'COMBO'], true);
        $needsChannels = in_array($type->code, ['CABLE', 'COMBO'], true);

        if ($needsSpeed && $speed === null) {
            throw ValidationException::withMessages(['speed_mbps' => "A {$type->name} plan needs a speed in Mbps."]);
        }
        if ($needsChannels && $channels === null) {
            throw ValidationException::withMessages(['channel_count' => "A {$type->name} plan needs a channel count."]);
        }

        $description = trim((string) ($data['description'] ?? ''));

        return [
            'name' => trim((string) $data['name']),
            'service_type_id' => $type->id,
            'price' => $price,
            'installation_fee' => $fee,
            // A field the plan type does not use is dropped, not kept stale.
            'speed_mbps' => $needsSpeed ? $speed : null,
            'channel_count' => $needsChannels ? $channels : null,
            'description' => $description === '' ? null : $description,
        ];
    }

    private static function wholeNumberOrNull(mixed $value): ?int
    {
        return $value === null || trim((string) $value) === '' ? null : (int) $value;
    }

    private static function money(mixed $text, string $field): Money
    {
        try {
            return Money::fromPesos((string) $text);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => 'Enter an amount such as 1,499.00 (at most two decimals).']);
        }
    }
}
