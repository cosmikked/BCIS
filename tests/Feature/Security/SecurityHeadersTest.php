<?php

declare(strict_types=1);

use App\Domain\Security\RoleCode;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Support\Users;

// NFR-15, FR-SEC-008

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->withoutVite();
    config(['app.debug' => false]);
});

function cspOf($response): string
{
    return (string) $response->headers->get('Content-Security-Policy');
}

it('sends the standard security headers on a page', function (): void {
    $response = $this->get('/login')->assertOk();

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'same-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');

    expect($response->headers->get('Permissions-Policy'))->toContain('camera=()', 'microphone=()', 'geolocation=()')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('sends them on JSON, error and redirect responses too', function (): void {
    foreach ([
        $this->getJson('/health'),
        $this->get('/no-such-page'),
        $this->get('/'), // redirect to sign-in
        $this->actingAs(Users::make('cashier', RoleCode::Cashier))->get(route('admin.users')), // 403
    ] as $response) {
        $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY');
        expect(cspOf($response))->toContain("frame-ancestors 'none'");
    }
});

it('enforces a restrictive Content-Security-Policy in production mode', function (): void {
    $csp = cspOf($this->get('/login'));

    expect($csp)->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'")
        ->toContain("frame-ancestors 'none'")
        ->not->toContain('*')
        ->not->toContain('http:')
        ->not->toContain('https:');

    // Scripts run only with the per-request nonce: no 'unsafe-inline' in script-src or style-src.
    $scriptSrc = collect(explode(';', $csp))->first(fn ($d) => str_contains($d, 'script-src ') && ! str_contains($d, 'script-src-'));
    $styleSrc = collect(explode(';', $csp))->first(fn ($d) => str_contains($d, ' style-src ') || str_starts_with(trim($d), 'style-src '));

    expect($scriptSrc)->toContain("'nonce-")->not->toContain("'unsafe-inline'")
        ->and($styleSrc)->toContain("'nonce-")->not->toContain("'unsafe-inline'");
});

it('stamps the policy nonce on the scripts the page prints, and uses a new one each request', function (): void {
    $first = $this->get('/login');
    $second = $this->get('/login');

    preg_match("/'nonce-([^']+)'/", cspOf($first), $a);
    preg_match("/'nonce-([^']+)'/", cspOf($second), $b);

    expect($a[1] ?? '')->not->toBe('')
        ->and($b[1] ?? '')->not->toBe('')
        ->and($a[1])->not->toBe($b[1])
        ->and($first->getContent())->toContain('nonce="'.$a[1].'"');
});

it('sends the policy as Report-Only while APP_DEBUG is on so the debug error page still works', function (): void {
    config(['app.debug' => true]);

    $response = $this->get('/login');

    expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and((string) $response->headers->get('Content-Security-Policy-Report-Only'))->toContain("default-src 'self'");
});

it('sends HSTS only over HTTPS', function (): void {
    $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
    $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('allows the Vite dev server only while it is running', function (): void {
    $hot = public_path('hot');
    expect(is_file($hot))->toBeFalse(); // not running here

    file_put_contents($hot, 'http://[::1]:5173');
    try {
        $csp = cspOf($this->get('/login'));
        expect($csp)->toContain('http://[::1]:5173')->toContain('ws://[::1]:5173');
    } finally {
        unlink($hot);
    }

    expect(cspOf($this->get('/login')))->not->toContain('5173');
});

// ------------------------------------------------------------------ cookies

function sessionCookieOf($response): Cookie
{
    $name = (string) config('session.cookie');
    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === $name);

    expect($cookie)->not->toBeNull('The response should set the session cookie.');

    return $cookie;
}

it('sets the session cookie HttpOnly and SameSite=Strict', function (): void {
    $cookie = sessionCookieOf($this->get('http://localhost/login'));

    expect($cookie->isHttpOnly())->toBeTrue()
        ->and(strtolower((string) $cookie->getSameSite()))->toBe('strict')
        ->and($cookie->getPath())->toBe('/');
});

it('adds the Secure flag when configured, for use behind HTTPS', function (): void {
    config(['session.secure' => true]);

    $cookie = sessionCookieOf($this->get('https://localhost/login'));

    expect($cookie->isSecure())->toBeTrue();
});

it('keeps sessions in the database by default, encrypted, with safe cookie flags', function (): void {
    // The test run overrides the driver to "array" (phpunit.xml), so read the shipped default from the file.
    // Encryption is likewise read from the file: a developer's .env.testing may predate it.
    $file = file_get_contents(base_path('config/session.php'));
    expect($file)->toContain("env('SESSION_DRIVER', 'database')")->toContain("env('SESSION_ENCRYPT', true)");

    expect(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('strict');
});

it('has CSRF protection in the web middleware group', function (): void {
    $web = app('router')->getMiddlewareGroups()['web'];

    // Laravel skips the check while tests run, so assert it is registered rather than exercised.
    expect(collect($web)->contains(fn ($m) => is_string($m) && (str_contains($m, 'VerifyCsrfToken') || str_contains($m, 'PreventRequestForgery'))))->toBeTrue();
});
