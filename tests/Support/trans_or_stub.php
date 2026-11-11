<?php

declare(strict_types=1);

/**
 * Stands in for the I18n plugin's global `trans_or()`, which Authorization's
 * `can` filter calls to word a refusal. In a project the I18n plugin (a declared
 * requirement of Authorization) provides it; the ground boots only a stub of that
 * domain, so without this a REFUSAL would be an undefined-function 500 while every
 * ALLOW passed. Returns the default, as the real one does with no catalogue.
 */

namespace {
    if (!function_exists('trans_or')) {
        /** @param array<string, string|int|float> $replace */
        function trans_or(string $key, string $default, array $replace = [], ?string $locale = null): string
        {
            return strtr($default, array_combine(array_map(static fn ($k) => ':' . $k, array_keys($replace)), array_map('strval', $replace)) ?: []);
        }
    }
}
