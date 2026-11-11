<?php

declare(strict_types=1);

namespace Plugins\Monitors\Infrastructure\Support;

final class Number
{
    /** 12.5000 -> "12.5", 3.0 -> "3". */
    public static function format(float $value): string
    {
        $text = number_format($value, 4, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
