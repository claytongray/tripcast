# Brief ↔ PRD Reconciliation — Tripcast

Source brief: `tripcast-brief.md`
Derived PRD: `prd-tripcast-2026-06-28/prd.md` + `addendum.md`
Date: 2026-06-28

Goal: catch brief content (requirements, positioning copy, specific values, UX detail,
success criteria) silently dropped or weakened in the PRD+addendum. The four intentional
changes (emails-through-return-date, welcome-then-quiet-then-daily cadence, pay-intent
instead of Stripe, new admin view) are excluded from "gaps" below.

---

## Method

Walked the brief section by section, mapping each assertion to a PRD FR / NFR / glossary
entry / addendum line, then graded: **Preserved**, **Weakened**, or **Dropped**.

---

## Genuine gaps (present in brief, missing or weakened in PRD+addendum)

### G1 — Geocoding ambiguity correctness downgraded from a hard success criterion to an assumption + open question  (WEAKENED — strongest)
- **Brief (line 189, Success Criteria for v1):** "Geocoding correctly resolves ambiguous
  city names (**Paris → France, not Texas**)." Stated as a must-pass v1 success criterion.
- **PRD:** FR-10 reframes this as `[ASSUMPTION: most-populous/most-likely match; no
  disambiguation picker in v1]`, and Open Question #5 asks whether "did you mean?"
  confirmation is even needed. The vivid "not Texas" failure example is gone.
- **Impact:** The brief guaranteed *correct* disambiguation as acceptance; the PRD only
  promises a best-guess match and leaves correctness unresolved. A naive most-populous
  match can fail the brief's own stated criterion (e.g., wrong "Paris"). This is a real
  lowering of the bar, not a rewording.

### G2 — Brief's concrete v1 acceptance checklist replaced wholesale by engagement metrics  (WEAKENED)
- **Brief (lines 181-189):** Success Criteria are a binary functional checklist —
  "User can add a trip", "Daily email sends at 9am EST with **accurate** 7-day forecast",
  "Email stops automatically", "Magic link auth works end to end", "Dashboard shows active
  trips", "Works for international destinations (Edinburgh, Tokyo)".
- **PRD §7 Success Metrics:** Replaced entirely with engagement/retention/pay-intent
  metrics (SM-1..SM-4). Each brief item *does* map to an FR, so capability is preserved,
  but the explicit "does it work end-to-end" acceptance framing — especially **forecast
  accuracy** as a pass/fail gate — no longer appears as a success/acceptance criterion
  anywhere. Forecast *accuracy* in particular is not asserted as testable acceptance.
- **Impact:** Moderate. Worth restoring a basic functional-acceptance checklist alongside
  the engagement metrics so "it actually works" is still a tracked gate.

### G3 — WeatherAPI Starter "Search API" capability dropped from provider notes  (DROPPED — minor)
- **Brief (line 78):** WeatherAPI Starter provides "7-day forecast, daily + hourly
  resolution, weather alerts, geocoding (unused), **Search API**."
- **Addendum (line 53):** Reduced to "also offers hourly + alerts (future)." The
  **Search API** capability and the explicit daily+hourly resolution framing are gone.
- **Impact:** Minor completeness loss; useful to keep as a record of what the paid plan
  already affords for future phases.

### G4 — Tagline "The weather app you never have to open" not anchored to the landing surface  (WEAKENED — minor)
- **Brief (line 8):** Lists this as the official product **Tagline**; line 142 separately
  gives landing positioning copy "Stop checking the weather for your trip. We'll do it for
  you."
- **PRD:** Uses the tagline only as a phrase inside §1 Vision. Landing FR-1 carries only
  the second line. The tagline is no longer tied to any user-facing surface / IA element.
- **Impact:** Minor, but the headline marketing line risks being lost as copy when it was
  brief-designated product positioning.

---

## Verified preserved (no action — spot-checked, faithful)

- Positioning: "passive trip-concierge meteorologist," competes with the *behavior* of
  checking weather, value delivered in the inbox with zero ongoing effort — §1 Vision. ✓
- Landing hero IS the inline trip-setup form; first interaction is the product, not a CTA;
  destination+dates preserved through email capture — FR-1, FR-2, UJ-1. ✓
- Email-only signup, one field, no password ever — FR-2, FR-3. ✓
- Magic-link-only auth, no password reset, sessions persist indefinitely refreshed on
  activity, explicit-logout-only — FR-3, FR-4. ✓
- Login-free unsubscribe / end-trip in every email footer — FR-5, NFRs. ✓
- Fixed 9:00 AM EST send — FR-6, addendum. ✓
- Forecast content: countdown, 7-day rolling, high/low in **both °F and °C**, conditions,
  precip probability, limited-data note — FR-7. ✓
- Email rendering: HTML via Laravel Mailable + Blade, single-column mobile-first,
  plain-text fallback, MailerSend (already integrated) — addendum, NFRs. ✓
- Geocoding: Google Maps, once at trip creation, store canonical name + lat/lng, never at
  send time, decoupled-from-weather rationale — FR-10, addendum. ✓
- Weather: WeatherAPI.com Starter $7/mo, fetch by coordinates, fresh each morning, provider
  abstracted behind a service class for future swap (Open-Meteo) — FR-11, addendum. ✓
- Tech stack: Laravel, Vue+Inertia with SSR for SEO, Laravel Scheduler single daily
  command, Laravel Forge — addendum. ✓
- Data model: Users (email, plan default 'beta', timezone default America/New_York, magic
  token+expiry, session token), Trips (all fields, status active/completed/paused), Email
  logs (trip, send date, status, weather snapshot) — addendum, Glossary. ✓
- Dashboard minimal: view upcoming, add, pause, delete, view past, logout; no analytics, no
  weather preview ("the email is the product") — FR-12. ✓
- Landing: single page in same app, SSR for SEO, no blog/docs/complex nav — FR-1 + ASSUMPTION. ✓
- Billing: no Stripe v1, all users beta/full-access, plan stubbed, no feature gates — §5/§6. ✓
- Out-of-scope list fully carried (Stripe, tz config, packing, events, flight status, native
  app, push, multi-destination, sharing) — §5 Non-Goals, §6.2, addendum Deferred. ✓
- External API costs: Google ~$5/1,000, WeatherAPI $7/mo, MailerSend configured — addendum. ✓
- International destinations (Edinburgh, Tokyo) resolve — FR-10. ✓

## Intentional changes (correctly NOT flagged)

- Emails continue **through the Return Date** (brief said stop on departure day). ✓ deliberate
- Cadence welcome-email → quiet → daily from Forecast Window 7 days out (brief said daily
  from subscription). ✓ deliberate
- Pay Intent capture instead of Stripe in v1. ✓ deliberate
- New Admin monitoring view (FR-13, UJ-4). ✓ deliberate addition

## Note: PRD enhancements beyond the brief (not gaps)

- Added explicit **Aesthetic & Tone** section (voice: calm, concierge, never alarmist;
  anti-references to busy/ad-heavy/anxiety-inducing weather UIs) — the brief had no tone
  section, so tone is *strengthened*, not dropped.
- Added Feedback Click engagement mechanism, Cross-Cutting NFRs (retry 3×/defer, idempotent
  sends, deliverability SPF/DKIM, observability, cost control), and richer user journeys.
