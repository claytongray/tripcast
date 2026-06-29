# PRD Quality Review — Tripcast (2026-06-28)

## Overall verdict

This is a genuinely strong lean PRD: it has a real thesis ("competes with the *behavior* of checking weather apps," §1), a disciplined Glossary, FRs that nearly all carry concrete testable consequences, success metrics that each cite the FRs they validate plus honest counter-metrics, and a scope section that names what's deferred and why. It is shaped correctly for what it is — a single-operator validation build with one admin journey — and it does not over-formalize. What's at risk is small and fixable: one real downstream-misleading ambiguity (`9:00 AM EST` vs `America/New_York` — DST fork), one self-contradiction where the Assumptions Index resolves a question the Open Questions list says is still open (rolling forecast), and a few mechanical drifts (an unindexed assumption, "resume" present in an FR but missing from MVP scope, light synonym drift on "Daily Digest"). None of these block a build; all should be cleaned before architecture/stories source-extract from it. **Gate: PASS-WITH-FIXES.**

## Decision-readiness — strong

A decision-maker can act on this. The central bet is stated, not buried: §1 frames the competitor as a *behavior* and §7 makes the validation thesis explicit (engagement + retention + pay-intent, with a post-v1 gate of "5 paid members"). Trade-offs are named with what was given up — fixed `9:00 AM EST` instead of per-user send times, most-likely geocoding match instead of a disambiguation picker, Pay Intent instead of real billing. The `[NOTE FOR PM]` at §6.2 ("Emotionally load-bearing: paid conversion is the headline business goal") is placed at a real tension, not a safe checkpoint. Open Questions (§8) are actually open — placement of the Pay Intent affordance, the rolling-vs-re-anchor forecast question, the same-day-signup edge — none are rhetorical.

### Findings
- **low** Resume capability is a decision absorbed silently (§6.1 vs FR-12) — FR-12 grants "pause… and resume it," but the MVP In-Scope bullet lists only "view/add/pause/delete/logout." Not a tension, just an incomplete echo. *Fix:* add "resume" to the §6.1 dashboard bullet.

## Substance over theater — strong

Little furniture here. Personas are not theater: there are exactly two (Maya the traveler, Clayton the builder/admin), each drives real FRs — Maya's journeys drive FR-1/2/3/6/7/8/12, Clayton's UJ-4 drives FR-13 and the Observability NFR. The Vision is product-specific ("the weather app you never have to open," the ambient-email collapse of the checking ritual) and would not swap cleanly into another PRD. NFRs carry product-specific thresholds and mechanics (idempotent sends via Email Log, retry ≤3× then defer to next day, cost "roughly linear in active trips × days") rather than "must be scalable/secure." The Aesthetic & Tone section even names anti-references. No innovation-theater differentiation section bolted on.

### Findings
- (none — dimension is earned.)

## Strategic coherence — strong

There is a thesis and the features serve it. Everything bends toward "deliver value in the inbox with zero ongoing effort": the dashboard is deliberately minimal ("No analytics, no weather preview — the email is the product," §4.5), auth is magic-link-only to keep setup to one field, and the cadence (welcome → quiet → daily) exists to make the email ambient rather than nagging. Success Metrics validate the thesis rather than measuring raw activity — SM-1 is *positive* Feedback Click share, SM-2 is trip-completion-without-opt-out, and open rate (SM-3) is explicitly demoted to a soft signal because the thesis is about quality of engagement, not eyeballs. Counter-metrics (SM-C1 unsubscribe/spam, SM-C2 pay-intent friction) are named and tied to the metrics they restrain. MVP scope is a coherent "problem-solving + validation" kind, and the scope logic matches.

### Findings
- (none.)

## Done-ness clarity — adequate

Every FR (FR-1 … FR-14) carries a **Consequences (testable)** block, and most consequences are genuinely verifiable: "Return Date ≥ Departure Date," "Exactly one field (email) is requested; no password is ever requested," "No Daily Digest is sent for an active Trip whose Departure Date is more than 7 days away," "Geocoding occurs exactly once per Trip at creation." This is the strongest part of the PRD for downstream story work. The cadence logic in FR-6 is fully enumerated including the inside-the-window creation edge and the de-dup guarantee. A few soft spots keep this from "strong."

### Findings
- **medium** `9:00 AM EST` is ambiguous against DST and contradicts the addendum (§4.3, §5, Cross-Cutting/Send Timing vs addendum "Send Timing Detail" / `America/New_York`) — "EST" is a fixed UTC−5 offset; `America/New_York` observes EDT (UTC−4) for ~8 months including the document's own date (2026-06-28). An engineer could implement a fixed UTC−5 cron or an NY-local cron and both would claim conformance, diverging by an hour for most of the year. This is the one requirement most likely to mislead architecture. *Fix:* pick one — state "9:00 AM America/New_York (clock time, DST-observing)" or "fixed 14:00 UTC" — and make PRD and addendum agree.
- **medium** Rolling-7-day forecast is simultaneously decided and open (FR-7 + §9 Assumptions Index vs §8 Open Question 2) — FR-7's consequence asserts "Shows a 7-day forecast," and the Assumptions Index states "forecast stays a rolling 7-day view," yet Open Question 2 says whether the forecast should "re-anchor on remaining trip days vs. a rolling 7-day window" is still open. The PRD both resolves and un-resolves the same thing; an architect building to the assumption may be reworked when the open question is answered. *Fix:* either close OQ-2 and keep the assumption, or downgrade the assumption to "[NOTE FOR PM] provisional" so it reads as not-yet-decided.
- **low** "Trip countdown/position" copy uses the short Destination, not the Canonical Place Name (FR-7) — the consequence says "Shows Canonical Place Name and a countdown ('4 days until Edinburgh')," but "Edinburgh" is the raw Destination, while Canonical Place Name is "Edinburgh, Scotland, UK." Harmless but technically inconsistent. *Fix:* clarify that the countdown line may use a short label derived from the Canonical Place Name.
- **low** Welcome Email "queued" vs "sent immediately" (FR-2 vs FR-9) — FR-2 says the Welcome Email is "queued," FR-9 says "Sent immediately on signup." Reconcilable (queued-then-sent) but worth one word of alignment.

## Scope honesty — strong

Omissions are explicit, not inferred. §5 Non-Goals does real work (no general weather app, no billing, no configurable send time, no hourly/radar/packing, no native app/push, no multi-destination, no passwords). §2.2 Non-Users mirrors this from the user side. Deferrals in §6.2 are stated with rationale and gating ("deferred to the fast-follow phase gated on the 5-paid-members milestone"). Open-items density is well-calibrated to the stakes: 6 Open Questions + 7 indexed assumptions + a handful of `[NOTE FOR PM]`s on a personal validation build is appropriate, not a blocker — these are genuine deferrals, not gaps masquerading as decisions.

### Findings
- (none material — see mechanical note on the unindexed privacy assumption.)

## Downstream usability — strong

This PRD will source-extract cleanly. The Glossary is present and disciplined; domain nouns (Trip, Forecast Window, Daily Digest, Email Log, Canonical Place Name, Trip Status) are used near-identically across FRs, UJs, and SM definitions. FR IDs are contiguous and unique (FR-1…FR-14), UJs are UJ-1…UJ-4, SMs are SM-1…SM-4 + SM-C1/SM-C2, and every cross-reference resolves (each SM's "Validates FR-x" points to a real FR; each Feature's "Realizes UJ-x" points to a real UJ). Sections largely stand alone via Glossary terms rather than "see above." The addendum cleanly quarantines the "how" (stack, data model, provider abstraction, API keys) and even flags new persistence needs (Pay Intent, Feedback Click) for the architect. UJs each have a named protagonist carrying context inline.

### Findings
- **low** Light synonym drift on "Daily Digest" (§4.3 Description, FR-9 consequence) — the defined term is "Daily Digest," but the prose uses "daily emails" (§4.3 Description: "daily emails do not start immediately") and lowercase "daily digests" (FR-9: "when daily digests will begin"). Readable, but glossary discipline wants the defined term. *Fix:* normalize to "Daily Digest."

## Shape fit — strong

The PRD matches its product. This is a single-operator validation build with a consumer-facing email surface, and it is formalized to exactly that level: UJs are load-bearing for Maya's three journeys (the email *is* the UX), while admin is given one operational UJ plus operational/observability NFRs rather than being forced into user-facing metrics. It is neither over-formalized (no UJ sprawl, two personas not six) nor under-formalized (a consumer-facing product that correctly keeps its UJs). The "design-for-future, build-for-small" framing (Scalability NFR) is honest about the intentional room to scale without gold-plating v1.

### Findings
- (none.)

## Mechanical notes

- **Assumptions Index roundtrip — one miss.** The inline `[ASSUMPTION: no formal GDPR/CCPA program in v1 personal beta; revisit before public scale.]` in the Privacy & data NFR (Cross-Cutting NFRs) is **not** listed in §9 Assumptions Index. Every other inline assumption (FR-1, FR-6, FR-7, FR-8, FR-10, FR-13, FR-14) round-trips correctly. *Fix:* add the privacy assumption to §9.
- **ID continuity — clean.** FR-1…FR-14 contiguous/unique; UJ-1…UJ-4; SM-1…SM-4 + SM-C1/SM-C2. No gaps, no duplicates, all "Validates FR-x" / "Realizes UJ-x" cross-refs resolve.
- **Glossary drift — minor.** "Daily Digest" appears as "daily emails" / lowercase "daily digests" in a few spots (see Downstream finding). "Magic Link," "Forecast Window," "Email Log," "Trip Status" are used consistently. Action labels ("end trip" / "end this trip" / "unsubscribe") vary but are UI copy, not defined terms — acceptable.
- **Conceptual term tension (note only).** "Forecast Window" is defined as the 7-day span *ending at Departure Date* "during which destination forecast data becomes available," yet Daily Digests (and fresh forecast fetches, FR-11) continue through the Return Date — i.e., after the window closes. The Glossary acknowledges this ("during and after the Forecast Window"), so it is not a contradiction, but the name implies forecast availability is gated to the pre-trip week when it isn't. Leave as-is for v1; flag if it confuses readers.
- **Required sections — all present** for the agreed stakes: Vision, Target User/JTBD, Non-Users, UJs, Glossary, Features+FRs, Non-Goals, MVP Scope, Success Metrics + counter-metrics, Open Questions, Assumptions Index, Cross-Cutting NFRs, Aesthetic/Tone, IA.

### Mechanical fix checklist
1. Reconcile `9:00 AM EST` ⇄ `America/New_York` across PRD §4.3/§5/NFR and addendum (DST decision). *(medium)*
2. Resolve the FR-7/§9 assumption vs §8 OQ-2 contradiction on rolling forecast. *(medium)*
3. Index the privacy `[ASSUMPTION]` in §9. *(low)*
4. Add "resume" to §6.1 In-Scope. *(low)*
5. Normalize "daily emails"/"daily digests" → "Daily Digest." *(low)*
6. Align FR-2 "queued" with FR-9 "sent immediately"; clarify countdown label vs Canonical Place Name. *(low)*
