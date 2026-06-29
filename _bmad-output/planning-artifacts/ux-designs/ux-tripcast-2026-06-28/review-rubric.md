# Spine Pair Review — tripcast

## Overall verdict

This is a strong, near-ship contract. Every PRD UJ and FR resolves to a Key Flow or a surface/component, vocabulary is inherited verbatim from the PRD glossary, and both spines obey their canonical shape. The one load-bearing weakness is in the DESIGN color system: several semantically-loaded colors (rain, positive, accent-wash, focus-ring, sunrise) have no dark-mode pair despite both spines repeatedly promising dark-mode-aware email and web, and the two text colors `rain` and `positive` appear to fall short of AA on their stated light surfaces with no contrast target committed. Fix the color/dark-mode gaps and add a DESIGN visual row for the admin table, and a downstream consumer can source-extract this cleanly.

## 1. Flow coverage — strong

Checked every UJ (UJ-1…UJ-4) and every FR (FR-1…FR-14) from prd.md against EXPERIENCE.md Key Flows, Component Patterns, IA, and State Patterns. All four UJs have a dedicated flow with named protagonist, numbered steps, an explicit Climax beat, a Resolution, and failure/edge paths where the journey carries risk (Flow 1 geocoding + magic-link failure; Flow 2 weather-down + partial-data edges). Flow 5 additionally covers FR-14 monetization intent (not a UJ but a requirement). FR-11 (fresh forecast fetch) is backend and surfaces only through the forecast block — acceptable.

### Findings
- **low** Flow 3 (dashboard management, UJ-3) has no failure path though it contains the only destructive action (delete) (EXPERIENCE.md:155-160). *Fix:* the "single calm confirm" rule already lives in Interaction Primitives; a one-line failure/confirm note in the flow would close the loop, but this is optional.

## 2. Token completeness — adequate

Extracted all YAML frontmatter tokens and every `{path.to.token}` prose reference. All `colors` carry hex values (no missing-hex CRITICAL). The two `{colors.focus-ring}` references in EXPERIENCE.md (lines 96, 113) resolve. DESIGN.md carries no `{path.to.token}` references or `components:` frontmatter block — components are prose-only, which is permitted by the spec and matches the Quill mobile example. Contrast targets are stated for `accent` and `ink-secondary`. The gaps are in dark-mode pairing and in load-bearing contrast that is asserted but not committed.

### Findings
- **high** `rain` (`#5B8FB0`) is specified as the text color for precipitation % in the forecast day-row (DESIGN.md:104, 145), but `#5B8FB0` on `surface-raised` `#FFFFFF` is only ~3.3:1 — below the AA 4.5:1 floor for normal text that EXPERIENCE.md:113 promises. No contrast target is stated for this combo. *Fix:* darken `rain` for text use (or set precip % in `ink-secondary` and reserve `rain` for the glyph only), and state the ratio in DESIGN.md Colors.
- **high** Dark-mode pairs are missing for `rain`, `positive`, `sunrise`, `accent-wash`, and `focus-ring`, yet both spines assert dark-mode-aware email and web (DESIGN.md:120,127; EXPERIENCE.md:111,120). `accent-wash` (`#EAF2FB`, a light tint) is the Active status-pill background (DESIGN.md:147) and is unrenderable on a dark surface; `focus-ring` has no dark variant although `accent` does. *Fix:* add `rain-dark`, `positive-dark`, `accent-wash-dark`, `focus-ring-dark` (and confirm `sunrise` in dark), each with a stated ratio.
- **medium** `positive` (`#3F8F6B`) is used as confirmation text ("Thanks — noted." DESIGN.md:105,149) but its contrast on `surface-raised`/`surface-base` (~3.9:1) is unstated and borderline for normal text. *Fix:* state the ratio; darken if it is body-sized text.
- **low** No contrast target stated for `sunrise` (`#E0993D`) as a glyph/motif (DESIGN.md:103). It is always text-paired and decorative-adjacent, so 3:1 UI applies, but committing the value removes ambiguity. *Fix:* note it meets the 3:1 UI floor or is paired text only.

## 3. Component coverage — adequate

Extracted every component named in either spine and checked for a DESIGN.md Components visual row AND an EXPERIENCE.md Component Patterns behavioral row. Matched cleanly: Trip-setup form, Email-capture, Magic-link CTA/card, Forecast block (+ day-row), Countdown/position line, Feedback chips, Unsubscribe/end-trip link, Trip card, Pay-intent action/screen. Status pill, Buttons, and Inputs have DESIGN rows with behavior covered via State Patterns / Interaction Primitives — acceptable.

### Findings
- **medium** Admin table has a full behavioral row (EXPERIENCE.md:77) and a flow (Flow 4) but **no DESIGN.md Components visual row** — the admin monitoring surface has zero visual specification. (DESIGN.md Components, lines 138-153.) *Fix:* add an Admin table row (it can be terse: inherits base type scale, hairline rows, horizontally-scrollable on small screens per the Responsive note) so a consumer isn't inventing it.
- **low** Geocoding confirm has a behavioral row (EXPERIENCE.md:70) but no dedicated DESIGN visual row; its appearance is only implied inside Trip-setup form. *Fix:* one line in the Trip-setup form DESIGN entry describing the canonical-name confirm + "edit destination" affordance.

## 4. State coverage — adequate

Walked every IA surface and listed expected states. Well covered: dashboard empty/active/paused/completed; geocoding ambiguous/failure; past/invalid dates; magic-link expired; limited weather data; weather-API-down (send nothing); transient send failure; feedback recorded; trip ended via link; web focus. Offline-for-web is omitted, which is defensible for an SSR, email-first product (the example spines included it because they were app-shaped) — worth a one-line "out of scope" note rather than silence.

### Findings
- **medium** No loading/pending state for the landing form submit while geocoding runs (FR-10 happens synchronously at creation). EXPERIENCE.md State Patterns jumps from input validation to ambiguous/failure with no in-flight treatment. *Fix:* add a "resolving destination…" pending state row so the consumer doesn't ship a frozen button.
- **low** Pay-intent idempotency is a behavioral rule (EXPERIENCE.md:76) but has no return-state ("you've already told us") in State Patterns. *Fix:* add a one-line already-expressed state on the Upgrade surface.
- **low** Dashboard and Admin have no cold-load/empty-error state rows (SSR makes cold-load less acute, and admin is operational). *Fix:* optional; note SSR-renders-server-side if you intend to skip skeletons.

## 5. Visual reference coverage — not applicable (correctly noted)

`imports/` and `.working/` exist but are empty; there is no `mockups/` or `wireframes/` directory anywhere under the workspace folder. EXPERIENCE.md:42 correctly states "Composition references will live in `mockups/` after Finalize," and DESIGN.md links to no mocks. This is the expected pre-Finalize state for a `status: draft` pair — no dangling links, no defect.

## Mechanical notes

- **Frontmatter completeness:** Both files carry `name`, `status: draft`, `sources` (both resolve to real files: prd.md and addendum.md verified present), and `updated`. DESIGN.md additionally has `description`, `colors`, `typography`, `rounded`, `spacing`. Nothing required is missing.
- **Inheritance discipline — strong.** Glossary terms are used verbatim: Canonical Place Name, Pay Intent, Feedback Click, Forecast Window, Magic Link, Daily Digest, Welcome Email, Trip Status. Personas match (Maya → UJ-1/2/3; Clayton → UJ-4). PRD Open-Question references resolve correctly (Q1 pay-intent placement, Q2 during-trip copy, Q5 geocoding disambiguation), each carrying an `[ASSUMPTION]` with a stated resolution.
- **Name consistency — one soft mismatch.** "Magic-link CTA" (EXPERIENCE.md:69) vs "Magic-link / interstitial card" (DESIGN.md:148) describe the same component under two labels; resolvable but worth aligning to one name.
- **Shape fit — strong.** DESIGN.md follows the canonical section order exactly (Brand & Style → Colors → Typography → Layout & Spacing → Elevation & Depth → Shapes → Components → Do's and Don'ts). EXPERIENCE.md includes all required defaults plus the triggered Responsive & Platform section (justified by the multi-surface email+web product).
- **Bloat — lean.** Density is high but load-bearing; the email-specific constraints (web-safe fonts, table layout, no shadows, plain-text pairing, progressive rounding) earn their space. A few "confirm at review" assumption tags are appropriate for a draft and need no trimming.
- **Cross-refs.** All `DESIGN.md.<Section>` and `EXPERIENCE.md.<Section>` pointers between the spines name real sections. `{colors.focus-ring}` is the only token-syntax reference across the prose and it resolves.
