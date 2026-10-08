<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Monolog processor that removes secrets from every log record (INV-24, NFR-06).
 *
 * Passwords, tokens, cookies and payment credentials must never reach a log
 * file. Two layers: values under sensitive KEYS are replaced, and secret-looking
 * patterns inside free-text strings (messages, exception text, SQL) are masked.
 */
final class RedactSensitiveData implements ProcessorInterface
{
    public const MASK = '[REDACTED]';

    /** Key fragments that mark a value as secret, matched case-insensitively. */
    private const SENSITIVE_KEY = '/(pass(word|wd|phrase)?|secret|token|authorization|cookie|api[-_]?key|credential|private[-_]?key|csrf|xsrf|session[-_]?id|(^|[-_.\s])(pin|otp|cvv|cvc)($|[-_.\s]))/i';

    /** key=value or key: value pairs and bearer tokens inside plain text. */
    private const SENSITIVE_TEXT = '/((?:pass(?:word|wd)?|secret|token|api[-_]?key|authorization)["\']?\s*[:=]\s*["\']?)([^\s"\',;&)]+)|(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->scrubString($record->message),
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    /**
     * Same key-based redaction for data that is not a log record, such as the
     * old and new values stored on an audit row (INV-24, INV-18).
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redactData(array $data): array
    {
        return $this->redact($data);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function redact(array $data, int $depth = 0): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                $clean[$key] = self::MASK;

                continue;
            }

            $clean[$key] = $this->redactValue($value, $depth);
        }

        return $clean;
    }

    private function redactValue(mixed $value, int $depth): mixed
    {
        if ($depth > 8) {
            return '[MAX DEPTH]';
        }

        return match (true) {
            is_array($value) => $this->redact($value, $depth + 1),
            is_string($value) => $this->scrubString($value),
            $value instanceof Throwable => $this->describeThrowable($value),
            is_object($value) => '['.get_class($value).']',
            default => $value,
        };
    }

    /**
     * Exceptions are turned into plain arrays so their message and trace can be
     * scrubbed. Query exceptions embed SQL with bound values, which may be secret.
     *
     * @return array<string, mixed>
     */
    private function describeThrowable(Throwable $e): array
    {
        return [
            'class' => $e::class,
            'message' => $this->scrubString($e->getMessage()),
            'code' => $e->getCode(),
            'file' => $e->getFile().':'.$e->getLine(),
            'trace' => $this->scrubString($e->getTraceAsString()),
        ];
    }

    private function scrubString(string $text): string
    {
        return (string) preg_replace_callback(
            self::SENSITIVE_TEXT,
            static fn (array $m): string => ($m[1] !== '' ? $m[1].self::MASK : ($m[3] ?? '').self::MASK),
            $text,
        );
    }
}
