/**
 * Display labels for the fixed weather-profile taxonomy (FR-26). Stored values
 * never change (`cold-wet` stays `cold-wet` — promo rotation and the frozen
 * rollback adapter key on them); only what admins see is renamed.
 */
export const WEATHER_PROFILE_LABELS: Record<string, string> = {
    snow: 'Snow',
    hot: 'Hot',
    rain: 'Rain',
    'cold-wet': 'Cold and rainy',
    cold: 'Cold',
    mild: 'Mild (legacy)',
    'travel-essentials': 'Travel essentials',
};

export function weatherProfileLabel(slug: string): string {
    return WEATHER_PROFILE_LABELS[slug] ?? slug;
}
