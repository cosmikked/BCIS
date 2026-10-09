<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Standard security headers on every response (NFR-15, FR-SEC-008).
 *
 * The Content-Security-Policy uses a fresh nonce per request. Laravel's Vite
 * helper and Livewire both read that nonce and stamp it on the script and style
 * tags they print, so only our own tags run; an injected <script> does not.
 *
 * Known limit, flagged for review: script-src keeps 'unsafe-eval' because
 * Livewire's standard Alpine build evaluates expressions. Livewire's
 * `csp_safe` option removes the need, but it must be proven in a real browser
 * before it is switched on.
 *
 * While APP_DEBUG is on, the policy is sent as Report-Only. Laravel's debug error
 * page uses inline scripts and would otherwise be blank; violations still show in
 * the browser console. With APP_DEBUG off (production) it is enforced.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $headers->set(config('app.debug') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy', $this->policy($nonce));

        // HSTS only over HTTPS. Sending it on plain HTTP does nothing, and the
        // LAN may run on HTTP (see README, Security notes).
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        // Pages show balances and personal data: keep them out of the browser cache.
        if (str_starts_with((string) $headers->get('Content-Type'), 'text/html')) {
            $headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        // `npm run dev` serves assets from the Vite dev server. Allow exactly that origin.
        $dev = $this->viteDevOrigin();
        $devSocket = $dev !== '' ? ' '.preg_replace('#^http#', 'ws', $dev) : '';

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'".($dev !== '' ? " {$dev}" : ''),
            "style-src 'self' 'nonce-{$nonce}'".($dev !== '' ? " {$dev}" : ''),
            // Style ATTRIBUTES only (Livewire sets some); style blocks still need the nonce.
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:".($dev !== '' ? " {$dev}" : ''),
            "connect-src 'self'".($dev !== '' ? " {$dev}{$devSocket}" : ''),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    private function viteDevOrigin(): string
    {
        $hot = public_path('hot');

        if (! is_file($hot)) {
            return '';
        }

        $url = trim((string) file_get_contents($hot));
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
