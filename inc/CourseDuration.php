<?php

/**
 * Formats one admin-entered expected lesson duration.
 * Invalid, zero, and unavailable values intentionally produce no display text.
 */
if (!function_exists('mmh_format_duration_minutes')) {
    function mmh_format_duration_minutes($minutes)
    {
        if ($minutes === null || $minutes === '') {
            return '';
        }

        if (is_int($minutes)) {
            $value = $minutes;
        } elseif (is_string($minutes) && ctype_digit(trim($minutes))) {
            $value = (int) trim($minutes);
        } else {
            return '';
        }

        if ($value < 1) {
            return '';
        }

        $hours = intdiv($value, 60);
        $remainingMinutes = $value % 60;

        if ($hours === 0) {
            return $remainingMinutes . ' min';
        }

        if ($remainingMinutes === 0) {
            return $hours . ' hr';
        }

        return $hours . ' hr ' . $remainingMinutes . ' min';
    }
}
