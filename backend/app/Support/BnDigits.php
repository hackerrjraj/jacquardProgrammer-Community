<?php

namespace App\Support;

/** Bengali digits (০–৯) typed by members → ASCII 0–9, so searches and amounts work in both. */
class BnDigits
{
    public static function toAscii(mixed $value): mixed
    {
        return is_string($value)
            ? strtr($value, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'])
            : $value;
    }

    /** ASCII 0–9 → Bengali digits, for text shown in Bengali (Phase 10a notifications). */
    public static function toBengali(string $value): string
    {
        return strtr($value, ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯']);
    }
}
