<?php

declare(strict_types=1);

/**
 * Stands in for the I18n plugin's global `__()`. Returns the key itself for
 * anything not in the map, which is what the real one does for a missing line.
 * Defined once and never removed (PHP cannot undefine a function); with an
 * empty map it is indistinguishable from "no translator".
 */

namespace Tests\Plugins\Teams\Support {
    final class FakeTranslator
    {
        /** @var array<string, string> */
        public static array $lines = [];
    }
}

namespace {
    if (!function_exists('__')) {
        /** @param array<string, string|int|float> $replace */
        function __(string $key, array $replace = [], ?string $locale = null): string
        {
            return \Tests\Plugins\Teams\Support\FakeTranslator::$lines[$key] ?? $key;
        }
    }
}
