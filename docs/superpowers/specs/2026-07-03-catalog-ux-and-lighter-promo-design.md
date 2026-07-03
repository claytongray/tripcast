# Catalog form UX, lighter email promo, and rain profile

**Date:** 2026-07-03
**Status:** Approved (pending spec review)

## Problem

Adding catalog items is confusing: the form leads with Slug (an internal
attribution key) instead of the product name, every field must be filled by
hand, and the image URLs are busywork for a slot that shouldn't look like a
banner ad anyway. The admin wants to add many products quickly. Separately,
the email promo unit reads too much like an ad (thumbnail + CTA button-ish
link), and the weather taxonomy has no profile for warm rain — rainy-but-mild
trips fall through to generic Essentials.

## Design

### 1. Create/edit form reorder + prefill (`resources/js/pages/Admin/Catalog/Form.vue`)

New field order: **Label → Slug → Description → Product URL → Merchant /
Weather profile → Featured window → Sort / Status**.

- **Slug auto-fills from Label** on create: kebab-case of the label
  (`"Repel Windproof Umbrella"` → `repel-windproof-umbrella`), client-side,
  live as you type. Hand-editing the slug sets a dirty flag that stops the
  sync. On edit the slug stays locked (immutable attribution key, AD-18) —
  unchanged behavior.
- **Merchant auto-detects from Product URL**: URL host containing `amazon.`
  selects `amazon`, anything else `other`. Same dirty-flag rule — manual
  choice wins. Still editable.
- No weather-profile guessing from the title (considered, rejected: not
  worth it).

### 2. Hide image fields

- Migration: `promo_items.image_url` becomes **nullable** (column and
  existing data are kept so images can return later).
- Form: Image URL field removed entirely.
- `PromoItemRequest`: `image_url` → `['nullable', 'string', 'url:https', 'max:2048']`.
- `Promo` DTO: `imageUrl` becomes `?string` (default `null`).
  `AffiliatePromoProvider` (frozen rollback adapter) still passes its config
  image through — only the type widens, no behavior change there.

### 3. Description field

- Migration (same file as §2): nullable `description` column,
  `string('description', 500)` to match the validation cap.
- Form: optional textarea under Slug. Request rule:
  `['nullable', 'string', 'max:500']`.
- `Promo` DTO gains `?string $description = null`; `DatabasePromoProvider`
  passes it through; the config fallback adapter passes `null`.

### 4. Lighter email promo unit

HTML digest (`resources/views/emails/digest.blade.php`):

```
SPONSORED                                   ← kept (FTC/affiliate disclosure)
Repel Windproof Travel Umbrella             ← label = the link, ink color
Packs to 11" and shrugs off coastal gusts.  ← description, secondary color (omitted when blank)
As an Amazon Associate, tripcast earns…     ← kept
```

- Thumbnail `<img>` and the separate "Shop now →" CTA line are removed; the
  label link is the only click target. Reads editorial, not banner.
- Text digest (`digest-text.blade.php`): label / description (when present) /
  raw URL.
- `promoCta` is removed end-to-end: `DigestMail` view data and the
  `tripcast.promo.cta` config key.

### 5. Rain weather profile

- `PromoItem::PROFILE_RAIN = 'rain'` added to `PROFILES` (plain string
  column — no schema change).
- `WeatherProfiler`: warm rain fills today's fall-through gap —
  `maxPrecip ≥ 50 && avgHigh ≥ 60°F` → `rain`. Cold rain
  (`≥ 50 && < 60°F`) keeps mapping to `cold-wet`. Order in the `match`:
  hot → cold-wet → rain → cold → null.
- `cold-wet` is **renamed in display only**: stored value stays `cold-wet`
  (no prod data migration, no edits to the frozen `AffiliatePromoProvider`).
  One shared display-label map renders "Cold and rainy", "Rain",
  "Travel essentials", etc. in the Form dropdown and the Index Profile
  column. The map lives with the shared frontend types (single source,
  imported by both pages).

### Out of scope

- Weather-profile keyword guessing from the title (rejected).
- Renaming the stored `cold-wet` value (rejected — migration effort).
- Removing `image_url` data or the column (kept nullable for later reuse).
- Post-image redesign of the Index table (it never showed images).

## Testing

- `PromoItemRequest`: description optional + max length, image_url optional,
  `rain` accepted on create, `mild` still create-forbidden/update-allowed.
- `WeatherProfiler`: warm rain → `rain`; cold rain → `cold-wet`; hot/dry
  unchanged; boundary at 60°F.
- `DatabasePromoProvider`: description passthrough; null image tolerated.
- Digest mail rendering: imageless unit, with and without description; no
  CTA line; text digest shape.
- Existing admin catalog feature tests updated for the field changes.
