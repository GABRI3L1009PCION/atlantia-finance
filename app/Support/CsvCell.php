<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Quotes one CSV cell so spreadsheets never read text as a formula (CSV injection): text that starts with =, +, -, @,
 * a tab or a carriage return gets a leading apostrophe. Numbers are written as they are.
 */
final class CsvCell
{
    public static function quote(mixed $value): string
    {
        $text = (string) $value;
        if (! is_int($value) && ! is_float($value) && preg_match('/^[=+\-@\t\r]/', $text) === 1) {
            $text = "'".$text;
        }

        return '"'.str_replace('"', '""', $text).'"';
    }
}
