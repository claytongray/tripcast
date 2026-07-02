/**
 * Today's calendar date in America/New_York as YYYY-MM-DD (en-CA gives ISO
 * order). Mirrors the server's validation anchor (AD-7) so native `min`
 * attributes agree with the FormRequest rules regardless of the viewer's
 * timezone — the server rule stays the only real authority.
 */
export function todayInEasternTime(): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'America/New_York',
    }).format(new Date());
}
