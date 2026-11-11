<?php

declare(strict_types=1);

/**
 * Stands in for the I18n plugin's global `__()`. Returns the key itself for
 * anything not in the map — which is what the real one does for a missing line,
 * and is the case Messages has to detect.
 *
 * Installed once per process and never removed (PHP cannot undefine a function);
 * with an empty map it is indistinguishable from "no translator", so installing
 * it does not change any other test.
 */

namespace Tests\Plugins\Monitors\Support {
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
            $line = \Tests\Plugins\Monitors\Support\FakeTranslator::$lines[$key] ?? $key;

            foreach ($replace as $name => $value) {
                $line = str_replace(':' . $name, (string) $value, $line);
            }

            return $line;
        }
    }
}
