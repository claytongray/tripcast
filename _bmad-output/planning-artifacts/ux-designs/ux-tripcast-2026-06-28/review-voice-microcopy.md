# Voice & Microcopy Review — tripcast

## Overall verdict

**Adequate — strong voice, thin coverage.** The copy that *is* specified is disciplined and exactly on-brand: calm, concierge-brief, never alarmist, one idea per line, and the Voice and Tone table's Do/Don't pairs are genuinely modeled in the flows (e.g. "Thursday looks wet — pack a shell," "Day 2 in Edinburgh," "Thanks — noted."). The weakness is not drift but *absence*: many load-bearing moments describe the copy ("clear error," "calm copy makes the case," "inline, specific message") without writing it, and the product's single most-seen surface — the email subject line and inbox preheader — has no strategy at all. Lock the missing strings and the spine is publishable.

## Findings

**[critical]** No subject-line or preheader strategy anywhere (EXPERIENCE.md — all email surfaces: welcome email, daily digest; DESIGN.md §83 declares "email is the product"). The inbox line is the first and most-repeated impression of a product whose entire premise is the inbox, yet it is unspecified. A careless default ("Your tripcast forecast for Edinburgh ⛅️ — rain expected!") would reintroduce exactly the daily anxiety the product dissolves. *Fix:* specify a calm, low-stakes pattern that reads like a concierge note, not a weather alert. Subjects — pre-trip: `"Edinburgh — 5 days to go"`; during trip: `"Edinburgh — day 2"`; welcome: `"We're watching Edinburgh"`; limited-data day: `"Edinburgh — your morning note"`. Preheaders — a one-line plain summary, never a warning: `"Thursday looks wet; the rest is mild. Highs 14–17°C."` Rule: no weather verdict in the subject, no emoji, no exclamation, place name leads, countdown is the hook (not the conditions).

**[high]** Welcome email body is only partially written (EXPERIENCE.md §33, §53, §141). The voice table gives one sentence ("We're watching Edinburgh. First digest arrives [date].") but the IA promises "destination, dates, when digests begin" — the full body, the subject, and the closing are unspecified. *Fix:* full body — `"We're watching Edinburgh, 14–21 July. Your first morning forecast arrives 9 July. Nothing to do until then — we'll be in your inbox."` Subject: `"We're watching Edinburgh"`. No CTA, no "get started," no celebration emoji.

**[high]** Validation-error copy is described but never written (EXPERIENCE.md §67 "rejected inline"; §89 "Inline, specific message"; DESIGN.md §152 "Clear inline validation text"). Three distinct first-run failure points (empty destination, return < departure, past departure) have no strings — the riskiest place for an implementer to fall back on framework defaults like "This field is required." *Fix:* empty destination — `"Where are you headed?"`; return before departure — `"Return is before departure — check the dates."`; past departure — `"That date's already passed — pick a future trip."` Specific, calm, no red-alarm language (DESIGN.md already mandates no red-fill drama).

**[high]** Geocoding-failure copy unspecified (EXPERIENCE.md §88 "Surface a clear error … Offer to retry/edit"). A core setup dead-end risk with only a behavioral note. *Fix:* `"We couldn't find that place. Try a city and country — like 'Edinburgh, UK'."` with a single "Edit destination" action. Helpful, not blaming.

**[high]** Pay-intent value copy unspecified (EXPERIENCE.md §76, §172 "calm copy makes the case"; DESIGN.md §150 "calm value copy"). The one persuasion surface in the product has only its button label ("I'd pay for this") written. Persuasion is where calm voices most easily slip into hype. *Fix:* body — `"tripcast is a side project we'd love to keep running. If these mornings have been worth it, tell us — no card, just a signal."` Button stays `"I'd pay for this."` Keep the honesty; ban urgency/scarcity copy.

**[high]** No calm lexicon for rough (non-mild) weather (EXPERIENCE.md §52, §71, §148–150). The voice table models only the gentlest case ("looks wet — pack a shell"). Real forecasts include storms, heat, snow, high wind — and a 7-day forecast block will render condition descriptions for all of them. Without a modeled vocabulary, implementers/condition-mapping will drift toward source-API phrasing ("Severe thunderstorms," "Heat advisory") that is precisely the alarmism the product bans. *Fix:* define the descriptor set and keep it plain and brief — `"heavy rain — bring a real coat"`, `"hot — pack light, plan shade"`, `"snow likely — warm layers"`, `"blustery — a windproof layer helps"`. Never "warning," "severe," "alert," "danger," "⚠️."

**[medium]** Check-your-email interstitial copy unspecified (EXPERIENCE.md §29, §139 "tells her a link is on its way"; DESIGN.md §148 mentions only the "Resend link" action). The screen exists with a described intent but no string. *Fix:* `"Check your inbox — we sent a link to {email}. It expires in [N] minutes."` plus the existing "Resend link."

**[medium]** Paused-trip copy unspecified (EXPERIENCE.md §85 "copy makes clear no emails send while paused"). *Fix:* `"Paused — no emails until you resume."` on the card; resume action `"Resume"`.

**[medium]** Delete-confirm copy unspecified (EXPERIENCE.md §103 "single calm confirm"). A destructive action with no string. *Fix:* `"Stop watching Edinburgh and remove it? This can't be undone."` Actions: `"Remove trip"` / `"Keep it."` Match the "watching" motif used in welcome/end-trip.

**[medium]** Countdown/position line not finalized (EXPERIENCE.md §72, marked [ASSUMPTION] / PRD Open Q2). Good candidate copy exists ("N days until {place}" / "Day N in {place}") but is unconfirmed and load-bearing — it is the digest header. *Fix:* confirm and lock; also specify the boundary cases: departure day (`"Today: Edinburgh."`) and last day (`"Last day in Edinburgh."`) so the header never reads "Day 0" or runs past the trip.

**[low]** Feedback prompt label unspecified (EXPERIENCE.md §73; DESIGN.md §149). The chips carry "This helped" / "Not helpful" but the line that introduces them isn't written. *Fix:* `"Was this useful?"` — or let the chip labels stand alone with no prompt (more in keeping with one-action-per-surface).

**[low]** Geocoding-confirm prompt + "edit destination" affordance label unwritten (EXPERIENCE.md §70). The canonical name is shown back ("Edinburgh, United Kingdom") but the surrounding confirm prompt isn't. *Fix:* `"Watching Edinburgh, United Kingdom. Not right? Edit destination."`

**[low]** "Next digest [time]" hint is a placeholder, not copy (EXPERIENCE.md §84, marked [ASSUMPTION]). *Fix:* `"Next forecast tomorrow, 9:00 AM."`

**[low]** Unsubscribe/end-trip footer *link label* unspecified (EXPERIENCE.md §74; the confirmation string exists at §55 but the clickable footer text doesn't). *Fix:* footer link `"End this trip"` (not "Unsubscribe (code 200)" — already banned). Confirmation reuses the strong existing line: "Your trip is wrapped. We've stopped watching — safe travels."

### Positives worth preserving
- The "watching" motif ("We're watching Edinburgh" → "We've stopped watching") is a quiet, consistent throughline — extend it to delete/confirm copy rather than introducing new verbs.
- Weather is genuinely de-dramatized in every specified string; the rain=informational-blue rule in DESIGN.md and the plain-language digest copy are mutually reinforcing.
- Email and web pull from one voice table; no cross-surface tone conflict was found in the copy that exists. The risk is entirely in the unwritten strings above — write them in the same register and consistency holds.
