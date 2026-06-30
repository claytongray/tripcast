<?php

namespace App\Digest;

use Illuminate\Support\Str;

/**
 * Maps a provider condition string to a single decorative weather emoji for the
 * digest. The condition **text** always renders alongside it (meaning never
 * lives in the glyph alone, UX-DR6) — the emoji is a calm visual cue only.
 */
class WeatherEmoji
{
    /**
     * Keyword → emoji, checked in priority order (most specific first, so
     * "thundery rain" reads as a storm and "light snow" as snow, not rain).
     * Tuned to cover the full WeatherAPI condition catalog (weather_conditions.json),
     * including the dust/sand/smoke/smog obscuration family. "storm" is NOT a
     * thunder keyword — a "dust storm" / "sandstorm" is low-visibility (🌫️), not
     * lightning; the real thunder conditions all carry the word "thunder".
     *
     * @var list<array{0: list<string>, 1: string}>
     */
    private const MAP = [
        [['thunder'], '⛈️'],
        [['blizzard', 'snow', 'sleet', 'ice', 'icy'], '🌨️'],
        [['rain', 'drizzle', 'shower'], '🌧️'],
        [['fog', 'mist', 'haze', 'dust', 'sand', 'smoke', 'smog'], '🌫️'],
        [['partly', 'partial'], '⛅'],
        [['overcast', 'cloud'], '☁️'],
        [['sun', 'clear'], '☀️'],
        [['wind'], '💨'],
    ];

    /**
     * The emoji for a condition, or '' when there is none (null/limited day or
     * an unrecognized phrase — the text still carries the meaning).
     */
    public static function for(?string $condition): string
    {
        if ($condition === null || trim($condition) === '') {
            return '';
        }

        $needle = Str::lower($condition);

        foreach (self::MAP as [$keywords, $emoji]) {
            foreach ($keywords as $keyword) {
                if (str_contains($needle, $keyword)) {
                    return $emoji;
                }
            }
        }

        return '';
    }
}
