<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Support;

use JsonException;

/**
 * Deterministic JSON for content digests.
 *
 * Two documents that mean the same thing must produce the same bytes, or a
 * digest over them reports drift that did not happen — which, for a
 * security control that fails closed, means an operator learns to ignore it.
 * So string-keyed maps are sorted recursively (JSON object key order carries
 * no meaning) while LISTS are left exactly as they are (array order can
 * carry meaning, and sorting would silently equate documents that differ).
 *
 * Slashes and unicode are left unescaped so the bytes match what a human
 * reading the same document would compute elsewhere.
 *
 * @internal
 */
final class CanonicalJson
{
    /**
     * @throws JsonException when the value cannot be encoded (invalid UTF-8
     *                       from an untrusted source) — surfaced, never
     *                       swallowed: a digest over a lossy rendering
     *                       would be worse than no digest
     */
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @throws JsonException
     */
    public static function digest(mixed $value): string
    {
        return 'sha256:'.hash('sha256', self::encode($value));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value);

        return array_map(self::canonicalize(...), $value);
    }
}
