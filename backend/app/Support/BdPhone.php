<?php

namespace App\Support;

/** Bangladeshi mobile numbers: stored as 11 digits, e.g. 01712345678. */
class BdPhone
{
    public const PATTERN = '/^01[3-9][0-9]{8}$/';

    /**
     * "+880 1712-345678", "8801712345678" and "01712345678" all become "01712345678".
     * Anything that is not a number shape is returned trimmed, so validation can reject it.
     */
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);
        $digits = preg_replace('/[\s\-]/', '', $value);

        if (preg_match('/^\+?880(1[0-9]{9})$/', $digits, $m)) {
            return '0'.$m[1];
        }

        return preg_match('/^[0-9]+$/', $digits) ? $digits : $value;
    }
}
