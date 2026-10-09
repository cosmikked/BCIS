@props(['status'])
@php
    // Status is always shown as text AND colour (NFR-13), never colour alone.
    $value = $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    $class = match ($value) {
        'ACTIVE' => 'badge-success',
        'PENDING' => 'badge-warning',
        'SUSPENDED' => 'badge-danger',
        default => 'badge-neutral',
    };
@endphp
<span {{ $attributes->class(['badge', $class]) }}>{{ ucfirst(strtolower($value)) }}</span>
