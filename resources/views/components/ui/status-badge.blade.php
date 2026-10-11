@props(['status'])
@php
    // Status is always shown as text AND colour (NFR-13), never colour alone.
    $value = $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    $class = match ($value) {
        'ACTIVE', 'PAID', 'APPROVED', 'FINALIZED' => 'badge-success',
        'PENDING', 'UNPAID', 'PARTIALLY_PAID' => 'badge-warning',
        'SUSPENDED', 'OVERDUE', 'REJECTED' => 'badge-danger',
        default => 'badge-neutral',
    };
@endphp
<span {{ $attributes->class(['badge', $class]) }}>{{ ucfirst(strtolower(str_replace('_', ' ', $value))) }}</span>
