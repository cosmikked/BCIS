<?php

declare(strict_types=1);

use App\Support\Logging\RedactSensitiveData;
use Monolog\Level;
use Monolog\LogRecord;

// NFR-06, INV-24

function logRecord(string $message, array $context = [], array $extra = []): LogRecord
{
    return new LogRecord(new DateTimeImmutable, 'test', Level::Info, $message, $context, $extra);
}

it('masks values under sensitive keys, at any depth', function (): void {
    $out = (new RedactSensitiveData)(logRecord('login', [
        'username' => 'demo.cashier',
        'password' => 'hunter2',
        'nested' => ['Authorization' => 'Bearer abc.def', 'api_key' => 'k-123', 'ok' => 'visible'],
        'remember_token' => 'tok',
        'cookie' => 'laravel_session=abc',
    ]));

    expect($out->context['username'])->toBe('demo.cashier')
        ->and($out->context['password'])->toBe('[REDACTED]')
        ->and($out->context['nested']['Authorization'])->toBe('[REDACTED]')
        ->and($out->context['nested']['api_key'])->toBe('[REDACTED]')
        ->and($out->context['nested']['ok'])->toBe('visible')
        ->and($out->context['remember_token'])->toBe('[REDACTED]')
        ->and($out->context['cookie'])->toBe('[REDACTED]');
});

it('masks secrets inside free text and exception messages', function (): void {
    $out = (new RedactSensitiveData)(logRecord(
        'request failed password=hunter2 and Bearer abc123.def',
        ['exception' => new RuntimeException('bad token: s3cr3t-value here')],
    ));

    expect($out->message)->not->toContain('hunter2')->not->toContain('abc123')
        ->and($out->message)->toContain('password=[REDACTED]')
        ->and($out->context['exception']['message'])->not->toContain('s3cr3t-value')
        ->and($out->context['exception']['class'])->toBe(RuntimeException::class);
});

it('leaves ordinary business data alone', function (): void {
    $out = (new RedactSensitiveData)(logRecord('payment posted', ['amount_centavos' => 149900, 'receipt_no' => 'RCPT-000001']));

    expect($out->context)->toBe(['amount_centavos' => 149900, 'receipt_no' => 'RCPT-000001']);
});
