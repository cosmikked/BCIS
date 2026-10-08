<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an id, puts it on every log line and returns it in the
 * X-Request-Id response header so support can match a user report to a log.
 */
final class RequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = $this->resolve($request);

        RequestContext::setRequestId($id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    /** Runs after the response is sent, so terminating work still logs the id. */
    public function terminate(Request $request, Response $response): void
    {
        RequestContext::setRequestId(null);
    }

    /**
     * Accept a caller-supplied id only if it is short and plain. Anything else
     * could forge log lines, so it is replaced with a fresh UUID.
     */
    private function resolve(Request $request): string
    {
        $supplied = $request->headers->get(self::HEADER);

        if (is_string($supplied) && preg_match('/^[A-Za-z0-9._-]{8,64}$/', $supplied) === 1) {
            return $supplied;
        }

        return (string) Str::uuid();
    }
}
