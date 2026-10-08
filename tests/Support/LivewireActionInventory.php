<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Security\SkipsAuthorization;
use ReflectionClass;
use ReflectionMethod;

/**
 * TO-08 / FR-SEC-005, FR-SEC-007: every public method of a Livewire component is
 * callable from the browser, so each one must authorize (Gate::authorize,
 * $this->authorize, can/cannot) or carry an explicit #[SkipsAuthorization].
 *
 * This is a source check: it proves a check is written, not that it is correct.
 * The behavioural tests (AT-10, the tamper tests) prove the refusals.
 */
final class LivewireActionInventory
{
    /** Framework hooks that never run user-triggered work of their own. */
    private const IGNORED = ['render', 'boot', 'booted', 'placeholder', 'rendering', 'rendered', 'exception', 'paginationView', 'paginationSimpleView'];

    private const AUTHORIZES = '/(->authorize\(|Gate::(authorize|allows|denies|check|inspect)\(|->can\(|->cannot\()/';

    /**
     * @param  list<class-string>  $components
     * @return list<string>
     */
    public static function violations(array $components): array
    {
        $violations = [];

        foreach ($components as $class) {
            $reflection = new ReflectionClass($class);

            if ($reflection->getAttributes(SkipsAuthorization::class) !== []) {
                continue;
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class || $method->isStatic() || self::ignored($method)) {
                    continue;
                }

                if ($method->getAttributes(SkipsAuthorization::class) !== []) {
                    continue;
                }

                if (preg_match(self::AUTHORIZES, self::source($method)) !== 1) {
                    $violations[] = "{$class}::{$method->getName()}() does not authorize.";
                }
            }
        }

        return $violations;
    }

    private static function ignored(ReflectionMethod $method): bool
    {
        $name = $method->getName();

        // Methods that come from a vendor trait (for example the public
        // authorizeResource() of AuthorizesRequests) are not ours to annotate.
        $file = (string) $method->getFileName();
        if (str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
            return true;
        }

        return $method->isConstructor()
            || str_starts_with($name, '__')
            || in_array($name, self::IGNORED, true)
            || str_starts_with($name, 'hydrate')
            || str_starts_with($name, 'dehydrate');
    }

    private static function source(ReflectionMethod $method): string
    {
        $file = $method->getFileName();

        if ($file === false) {
            return '';
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $start = $method->getStartLine() - 1;

        return implode("\n", array_slice($lines, $start, $method->getEndLine() - $start));
    }
}
