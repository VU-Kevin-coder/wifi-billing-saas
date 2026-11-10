<?php

namespace App\Support;

/**
 * Fills the operator's message template ("Your package expires in @hours_left").
 * Unknown @words are left untouched so a typo is visible rather than silent.
 */
class SmsTemplate
{
    /**
     * @param  array<string, string>  $values  keyed without the "@"
     */
    public static function render(string $template, array $values): string
    {
        // Longest names first so "@package_name" is never clipped by "@package".
        uksort($values, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        $replacements = [];
        foreach ($values as $name => $value) {
            // A value must never break the message layout: no newlines, no runs of spaces.
            $replacements['@'.$name] = trim((string) preg_replace('/\s+/', ' ', (string) $value));
        }

        return strtr($template, $replacements);
    }

    /** Number of SMS segments (GSM-7 assumed: 160 chars, then 153 per part). */
    public static function segments(string $message): int
    {
        $length = mb_strlen($message);

        return $length <= 160 ? 1 : (int) ceil($length / 153);
    }
}
