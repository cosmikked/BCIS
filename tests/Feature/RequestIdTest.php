<?php

declare(strict_types=1);

use App\Http\Middleware\RequestId;
use App\Support\Logging\ConfigureJsonLog;
use App\Support\RequestContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

// NFR-06

it('adds a generated request id to the response', function (): void {
    $response = $this->get('/health');

    expect($response->headers->get(RequestId::HEADER))->toMatch('/^[0-9a-f-]{36}$/');
});

it('keeps a safe caller-supplied request id', function (): void {
    $response = $this->get('/health', [RequestId::HEADER => 'abc-12345678']);

    expect($response->headers->get(RequestId::HEADER))->toBe('abc-12345678');
});

it('replaces an unsafe caller-supplied request id', function (): void {
    $response = $this->get('/health', [RequestId::HEADER => 'bad"id with spaces']);

    expect($response->headers->get(RequestId::HEADER))->toMatch('/^[0-9a-f-]{36}$/');
});

it('writes JSON log lines with request_id and no secrets', function (): void {
    $path = storage_path('logs/test-json-'.uniqid().'.log');
    config(['logging.channels.test_json' => [
        'driver' => 'single',
        'path' => $path,
        'level' => 'debug',
        'tap' => [ConfigureJsonLog::class],
    ]]);

    Route::get('/_log-probe', function () {
        Log::channel('test_json')->info('probe', ['password' => 'hunter2', 'invoice' => 'INV-000001']);

        return 'ok';
    });

    $response = $this->get('/_log-probe', [RequestId::HEADER => 'probe-request-1']);
    $response->assertOk();

    $line = json_decode(trim((string) file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);
    @unlink($path);

    expect($line['request_id'])->toBe('probe-request-1')
        ->and($line['message'])->toBe('probe')
        ->and($line['context']['password'])->toBe('[REDACTED]')
        ->and($line['context']['invoice'])->toBe('INV-000001');
});

it('uses "cli" outside a web request', function (): void {
    RequestContext::setRequestId(null);

    expect(RequestContext::requestId())->toBe('cli');
});
