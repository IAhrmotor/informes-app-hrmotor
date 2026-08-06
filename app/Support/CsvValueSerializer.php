<?php

namespace App\Support;

final class CsvValueSerializer
{
    public static function serialize(mixed $value): string|int|float
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value) || is_object($value)) {
            $encoded = json_encode(
                $value,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
            );

            return $encoded === false ? '' : $encoded;
        }

        return is_int($value) || is_float($value) ? $value : (string) $value;
    }
}
