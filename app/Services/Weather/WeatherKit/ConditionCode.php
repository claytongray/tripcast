<?php

namespace App\Services\Weather\WeatherKit;

/**
 * Turns WeatherKit's PascalCase `conditionCode` enum (e.g. `PartlyCloudy`,
 * `ScatteredThunderstorms`) into a spaced human label ("Partly Cloudy",
 * "Scattered Thunderstorms"). The label is what renders in the digest and what
 * feeds the existing keyword-based WeatherEmoji — so no code→emoji table is
 * needed and an unseen future code still degrades to readable text.
 */
class ConditionCode
{
    public static function label(string $code): string
    {
        $code = trim($code);

        if ($code === '') {
            return '';
        }

        // Insert a space before each interior capital: "HeavyRain" → "Heavy Rain".
        // preg_replace returns string|null (null only on PCRE engine error); fall
        // back to the raw code so the declared string return always holds.
        return preg_replace('/(?<!^)(?=[A-Z])/', ' ', $code) ?? $code;
    }
}
