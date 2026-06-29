---
name: tripcast
description: The weather app you never have to open. A calm, airy, atmospheric-sky trip-weather digest — email-first, never alarmist.
status: final
sources:
  - "{planning_artifacts}/prds/prd-tripcast-2026-06-28/prd.md"
  - "{planning_artifacts}/prds/prd-tripcast-2026-06-28/addendum.md"
colors:
  surface-base: '#F6F9FC'
  surface-raised: '#FFFFFF'
  surface-wash: '#EAF2FB'
  ink-primary: '#16202B'
  ink-secondary: '#51616E'
  ink-disabled: '#9FB0BD'
  accent: '#2563A6'
  accent-hover: '#1E5391'
  accent-wash: '#EAF2FB'
  sunrise: '#E0993D'
  rain: '#5B8FB0'
  positive: '#2E7D5B'
  border-hairline: '#E3EAF1'
  focus-ring: '#2563A6'
  surface-base-dark: '#0E1822'
  surface-raised-dark: '#16232F'
  surface-wash-dark: '#1B2D3D'
  ink-primary-dark: '#E8EEF4'
  ink-secondary-dark: '#9FB0BF'
  ink-disabled-dark: '#5A6B79'
  accent-dark: '#6BA7E0'
  accent-hover-dark: '#85B7E8'
  accent-wash-dark: '#22384A'
  sunrise-dark: '#EDB266'
  rain-dark: '#7FB3D1'
  positive-dark: '#6FC79A'
  focus-ring-dark: '#6BA7E0'
  border-hairline-dark: '#243340'
typography:
  family-ui:
    note: '[ASSUMPTION] Web app — Inter (variable), fallback system-ui, -apple-system, Segoe UI, sans-serif'
  family-email:
    note: 'Email — web-safe stack only for deliverability: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif. No web fonts in email.'
  display:
    size: 30px
    line: 38px
    weight: 600
    note: 'Landing hero headline / place name in digest header'
  title:
    size: 22px
    line: 30px
    weight: 600
  subtitle:
    size: 17px
    line: 26px
    weight: 500
  body:
    size: 16px
    line: 26px
    weight: 400
  meta:
    size: 13px
    line: 20px
    weight: 400
    note: 'Captions, dates, footnotes, unsubscribe line'
  temp:
    size: 18px
    line: 22px
    weight: 600
    note: 'Forecast high/low figures — tabular numerals'
rounded:
  sm: 8px
  md: 14px
  lg: 20px
  full: 999px
spacing:
  '1': 4px
  '2': 8px
  '3': 12px
  '4': 16px
  '5': 24px
  '6': 32px
  '7': 48px
  '8': 64px
updated: 2026-06-29
---

# tripcast — Design Spine

> Visual identity for tripcast. Multi-surface: **email is the product**; a small SSR web app (landing · dashboard · admin) surrounds it. Paired with `EXPERIENCE.md`. Posture: calm, airy, atmospheric, never alarmist. Email constraints (web-safe fonts, table layout, inline styles, dark-mode resilience) are first-class here, not afterthoughts.

## Brand & Style

tripcast competes not with weather apps but with the *behavior* of compulsively checking them. The design must therefore feel like the opposite of a weather dashboard: no dense panels, no radar, no red severe-weather drama, no badges screaming for attention. It is a quiet morning email that says, calmly, *here's your trip — we've got it._

The visual language is **calm, airy, atmospheric**. Soft sky-tinted surfaces, generous whitespace, a single confident sky-blue accent, and one warm sunrise note reserved strictly for the morning/countdown motif. Light, restful, and confident — the product equivalent of a deep breath. Weather is communicated, never dramatized: precipitation is a soft slate-blue, never an alarm red.

The voice is concierge-calm and brief (microcopy specifics live in `EXPERIENCE.md.Voice and Tone`). The tagline **"The weather app you never have to open."** is a brand asset and must appear on the landing page.

## Colors

A restrained atmospheric-sky palette. One chromatic accent (sky blue) carries every primary action; everything else is sky-tinted neutral. The point is restfulness — color never competes with the forecast.

- **Surface Base (`#F6F9FC` / dark `#0E1822`)** — the canvas. A cool, barely-there sky tint in light; a deep night-sky in dark. Never pure white as the page base; the slight blue is the atmosphere.
- **Surface Raised (`#FFFFFF` / dark `#16232F`)** — cards, the forecast block, the composer/form surfaces. Distinguished from base by tone, not shadow.
- **Surface Wash (`#EAF2FB` / dark `#1B2D3D`)** — pale sky tint for the landing hero band and gentle section fills. The closest the palette comes to a "dawn gradient" — used flat, not as a literal gradient, to stay calm and email-safe.
- **Ink Primary (`#16202B` / dark `#E8EEF4`)** — body and headings. A deep slate-ink, warmer and softer than pure black.
- **Ink Secondary (`#51616E` / dark `#9FB0BF`)** — supporting text, dates, secondary labels. Holds AA (≥4.5:1) on Surface Base.
- **Accent — Sky (`#2563A6` / dark `#6BA7E0`)** — the only action color. Buttons, links, the magic-link CTA, the primary submit. White text on the light accent and Ink-Primary-dark text on the dark accent both clear AA.
- **Sunrise (`#E0993D` / dark `#EDB266`)** — a single warm motif color, reserved *exclusively* for the morning/countdown motif (e.g. a small sun glyph or the "N days until" accent). Never for buttons, never for alerts, never decoratively. Motif/glyph use only (paired with text), so the 3:1 UI floor applies, not 4.5:1. [ASSUMPTION] motif-only usage; confirm at review.
- **Rain (`#5B8FB0` / dark `#7FB3D1`)** — soft slate-blue for the precipitation/wet-condition **glyph only** (UI element, meets the 3:1 floor on its surface). tripcast is *never alarmist*: wet weather is informational blue, never warning red/orange. **Precipitation-probability text uses `ink-secondary`, not `rain`** — `#5B8FB0` is ~3.3:1 on white and must not carry normal-size text.
- **Positive (`#2E7D5B` / dark `#6FC79A`)** — quiet confirmation only (e.g. "Thanks — noted." after feedback). Text/icon, sparingly; darkened from the initial draft to clear AA (~4.8:1 on `surface-raised`).
- **Accent-wash (dark `#22384A`)**, **Focus-ring (dark `#6BA7E0`)** — each carries a dark-mode pair so the Active status pill and focus outline render on dark surfaces.
- **Hairline (`#E3EAF1` / dark `#243340`)** — lowest-legible dividers and input borders. Anything heavier reads as a dashboard.

**Stated contrast targets (AA):** `ink-primary` on `surface-base`/`surface-raised` ≥ 12:1; `ink-secondary` on `surface-base` ≥ 4.5:1; `accent` with white text ≥ 4.5:1 and `accent` link text on white ≥ 4.5:1; `positive` text on `surface-raised` ≥ 4.5:1; `rain`/`sunrise` glyphs ≥ 3:1 (UI floor, always text-paired). Dark-mode pairs hold the same floors against their dark surfaces.

**Dark-mode rendering (email + web):** ship `<meta name="color-scheme" content="light dark">` plus `color-scheme: light dark; supported-color-schemes: light dark;` and a `@media (prefers-color-scheme: dark)` block mapping each light token to its `-dark` pair (Apple Mail / iOS / Apple-webkit web honor this). Accept that **Outlook.com / Outlook mobile force-invert** and ignore media queries, and **Gmail Android applies forced-dark** to light backgrounds — design so the worst-case inversion stays legible (mid-tone glyph strokes or a `surface-raised` chip behind each glyph so a dark-on-dark glyph never disappears). Never assume a white background.

Avoid: red/amber severe-weather fills, multi-color condition coding, gradients in email, saturated accent variants. One accent; sunrise is a whisper.

## Typography

Two stacks by surface. The **web app** uses Inter (variable) with a system fallback — clean, humanist, calm. **Email uses a web-safe stack only** (`-apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif`); no web fonts ship in email, for deliverability and client support. Both stacks share the same scale and rhythm so email and web feel like one product. [ASSUMPTION] Inter as the UI face — swap freely; the scale is the contract, not the family.

Scale (size / line / weight): **display** 30/38/600 · **title** 22/30/600 · **subtitle** 17/26/500 · **body** 16/26/400 · **meta** 13/20/400 · **temp** 18/22/600 (tabular numerals so high/low columns align).

Rules: generous line-height everywhere (airy). Headlines are rare — the landing hero, the place name in the digest header. No all-caps labels, no display sizes beyond `display`. Temperature figures use tabular/lining numerals so °F and °C columns stay aligned across days. Body copy max line length ~60–70ch on web for readability.

## Layout & Spacing

Scale: 4 / 8 / 12 / 16 / 24 / 32 / 48 / 64 px. Largest gaps separate major surfaces (hero from form, forecast block from footer); smallest sit inside tightly-related groups (a day's high/low pair).

- **Email:** single-column, mobile-first, **fluid `width: 100%` capped at `max-width: 600px`** (not a fixed 600px table — it must not overflow narrow viewports), centered, table-based layout with inline styles. Comfortable side padding (24px) so it breathes on phones. One column always — no multi-column forecast grids that collapse badly in clients. Hosted glyph images ship at **2× retina, served at 1× box dimensions** with explicit `width`/`height`.
- **Web (landing/dashboard/admin):** single centered column / content rail. Landing max ~720px for the hero+form; dashboard list max ~880px. Generous vertical rhythm. No sidebars on landing; dashboard may use a slim top bar only.

## Elevation & Depth

tripcast avoids elevation as hierarchy — calm and airy means flat. Cards and the forecast block sit on `surface-raised`, set apart from `surface-base` by tone and a hairline, not shadow. **Email ships no shadows** (poor, inconsistent client support); depth there is tone + hairline only. On web, at most one soft, low shadow is permitted on the floating primary CTA or a modal — never on list rows or the forecast block. Hierarchy comes from whitespace and type, not stacking.

## Shapes

- `rounded/sm` (8px) — inputs, list rows, small surfaces.
- `rounded/md` (14px) — cards, the forecast block, the composer/setup form, email content containers.
- `rounded/lg` (20px) — the landing hero band / large feature surfaces.
- `rounded/full` — **web only** (primary web CTAs and any web feedback chips). [ASSUMPTION] pill CTA on web; a 14px-radius button is an equally valid calm choice — confirm at review.

Soft corners throughout — atmospheric, not sharp. **In email, cap every element — containers *and* interactive buttons/chips — at `sm`–`md`.** Outlook (Word engine) ignores `border-radius` entirely, so a `full`-radius pill falls back to a sharp rectangle and looks broken; either keep email radius small or use the VML roundrect technique for bulletproof Outlook buttons. Treat rounding as progressive enhancement (square fallback is acceptable).

## Components

Visual specs only; behavior lives in `EXPERIENCE.md.Component Patterns`. Illustrative mocks (spine wins on conflict): [`mockups/digest-email.html`](mockups/digest-email.html) (Digest email · Forecast day-row · Feedback chips), [`mockups/landing.html`](mockups/landing.html) (Trip-setup form · Buttons · Inputs), [`mockups/dashboard.html`](mockups/dashboard.html) (Trip card · Status pill).

- **Trip-setup form (landing hero)** — `surface-raised` card on the `surface-wash` hero band, `rounded/lg`. Destination field full-width, date range below, single accent submit. The form *is* the hero; the tagline sits above it. After submit, the resolved **Canonical Place Name** is shown back inline ("Edinburgh, United Kingdom") with a quiet "Edit destination" text affordance — passive confirm, no "did you mean?" picker.
- **Email-capture step** — same card language, a single email input + accent submit. One field, nothing else.
- **Digest email** — `surface-base` outer, fluid `surface-raised` content card (max 600px). Header: place name in `display`, countdown/position line ("4 days until Edinburgh" / "Day 2 in Edinburgh") with optional sunrise sun glyph. Forecast: 7 stacked day-rows, each — day label (`meta`) · condition glyph + short description (`body`) · high/low in both °F and °C (`temp`, tabular) · precip % in **`ink-secondary`** (the `rain` color is the glyph, not the number). **Promo slot (free-tier only, below the forecast):** an optional single **Affiliate promo unit** (see below), separated from the forecast by a hairline so it reads as a quiet recommendation, never a banner — omitted entirely when absent. Footer: feedback line (👍/👎 with text labels), "End this trip" link in `meta`/`ink-secondary`, a stable **physical postal address line** (CAN-SPAM), and the plain-text fallback always paired. No information lives in an image alone — the email must be fully legible with all images suppressed.
- **Forecast day-row** — hairline divider between rows, no fill. Weather glyphs ship as **hosted PNG (2× retina, served 1×) with meaningful `alt` and explicit `width`/`height`** — **never inline SVG or icon fonts** (Gmail/Outlook/Yahoo strip or mis-render them). Glyphs are monochrome line-style (clear in `sunrise`, wet in `rain`); never multicolor weather emoji. Each glyph's meaning also lives in the adjacent text ("Rain likely") so the row reads with images blocked, and glyphs use a mid-tone stroke (or a `surface-raised` chip) so forced-dark inversion can't erase them.
- **Trip card (dashboard)** — `surface-raised`, `rounded/md`, hairline. Destination (`subtitle`) · dates + days-until-departure (`meta`) · status pill · row actions (pause/resume/delete). No weather preview (per PRD).
- **Status pill** — small, low-contrast tonal chips: Active (accent-wash + accent text), Paused (neutral hairline + ink-secondary), Completed (muted), each label-text-driven, not color-only (AA: pair color with text).
- **Magic-link CTA (email) & interstitial/result card (web)** — in email, one accent button; on web, a centered `surface-raised` card for the check-your-email interstitial and the expired/used result, calm copy, single accent action ("Resend link").
- **Admin table** ([`mockups/admin.html`](mockups/admin.html)) — operational, intentionally plain. Inherits the base type scale; hairline row dividers, no fill, no card chrome; columns: destination · canonical name · dates · status pill · owner · latest forecast snapshot · email-log (sent/failed + reason). Desktop-primary; horizontally scrollable on small screens (per Responsive note). Visual restraint over polish — it's a monitoring surface, not a CMS.
- **Feedback chips** — 👍 / 👎 **always paired with visible text** ("👍 This helped" / "👎 Not helpful") so the control works even where the emoji glyph renders oddly or is absent. In email cap at `rounded/md` (not `full`) and ensure ≥44px tap targets; `rounded/full` is web-only. Ink-secondary default, accent on the chosen one; collapse to a `positive` "Thanks — noted." line after tap.
- **Affiliate promo unit (digest, free-tier only)** — one calm, native recommendation in the digest promo slot below the forecast: a small hosted PNG product thumbnail (2× retina, `alt` + `width`/`height`, like the weather glyphs), a one-line product label in `body`, and a single accent text link ("View on Amazon") — at most **one** unit, no second item, no price, no star ratings, no "deal" urgency. A **mandatory disclosure line** in `meta`/`ink-secondary` sits with it: *"As an Amazon Associate, tripcast earns from qualifying purchases."* Reads as concierge help, not an ad; weather-keyed so it matches the forecast (a rain shell when it's wet). Suppressed entirely for `ad_free` users and absent on any selection error. Never appears in the subject line or preheader. The plain-text twin carries the label, the literal link, and the disclosure.
- **Buttons** — primary: accent fill, white text, `rounded/full` or `md`. Secondary: text/ghost in accent. No more than one primary action per surface.
- **Inputs** — `surface-raised`, hairline border, `rounded/sm`, visible `focus-ring` (accent, 2px, with offset). Clear inline validation text in `ink-secondary` (errors readable, not red-fill drama).

## Do's and Don'ts

| Do | Don't |
|---|---|
| One sky-blue accent for all actions; sunrise as a whisper | Multi-color UI, severe-weather red/orange, gradient fills in email |
| Communicate weather calmly (rain = soft blue, informational) | Alarmist styling, warning badges, dense radar-style panels |
| Single-column, 600px, table-layout, inline-styled email | Multi-column forecast grids or CSS-grid email that breaks in clients |
| Web-safe font stack in email; Inter on web | Web fonts in email; display type for body |
| Tone + hairline for hierarchy; whitespace to breathe | Shadows in email; elevation as the hierarchy device |
| Pair status color with a text label (AA, color-blind safe) | Color-only status, color-only condition coding |
| Tabular numerals so °F/°C columns align | Proportional figures that make forecast rows ragged |
| Hosted PNG glyphs (2×) with alt + adjacent text | Inline SVG, icon fonts, or info living in an image alone |
| Cap email button/chip radius at `sm`–`md` | `rounded/full` pills in email (square fallback looks broken) |
| Ship `color-scheme` meta + dark-pair tokens; design for forced inversion | Assume a white email background |
| Plain-text fallback paired with every HTML email | HTML-only sends |
