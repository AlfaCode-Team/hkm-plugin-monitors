<?php

declare(strict_types=1);

namespace Plugins\Monitors\Infrastructure\Support;

/**
 * Looks a message up through the I18n plugin's translator when there is one,
 * and in this plugin's own English catalogue when there is not.
 *
 * `__()` belongs to the I18n plugin, not the kernel: calling it unguarded would
 * be a fatal on an application that has not installed it, and an alert that
 * cannot be worded is an alert nobody receives. Reading our own `en` file is the
 * floor, so the copy still lives in resources/lang and never in code.
 */
final class Messages
{
    /** @var array<string, string>|null */
    private ?array $fallback = null;

    public function __construct(private readonly ?string $langDir = null) {}

    /** @param array<string, string|int|float> $replace */
    public function get(string $key, array $replace = []): string
    {
        if (\function_exists('__')) {
            $translated = __("monitors::alert.{$key}", $replace);

            if ($translated !== "monitors::alert.{$key}") {
                return $translated;
            }
        }

        $line = $this->catalogue()[$key] ?? $key;

        foreach ($replace as $name => $value) {
            $line = str_replace(':' . $name, (string) $value, $line);
        }

        return $line;
    }

    /** @return array<string, string> */
    private function catalogue(): array
    {
        if ($this->fallback !== null) {
            return $this->fallback;
        }

        $file = ($this->langDir ?? \dirname(__DIR__, 2) . '/resources/lang') . '/en/alert.php';
        $data = is_file($file) ? require $file : [];

        return $this->fallback = \is_array($data) ? $data : [];
    }
}
