# User settings page

**Date:** 2026-06-30
**Status:** Approved — ready for implementation plan
**Series:** Spec A of two (settings area; the per-trip next-send dashboard status is a separate, later spec)

## Problem

A logged-in user has no place to manage their account. The temperature unit
(`users.temperature_unit`) is captured once at signup via the landing toggle and
then drives every digest, but there is no way to change it afterward. There is
also no single home for account actions — Log out currently sits alone in the
top bar.

## Goal

A dedicated `/settings` page where a user can:

- Change their temperature unit (Fahrenheit / Celsius), saved immediately.
- See their email address (read-only for now).
- Log out.

## Out of scope

Delete account, changing the email address, billing, and surfacing past trips in
settings. These are explicitly deferred. The per-trip next-send dashboard status
("beacon" + countdown) is Spec B, designed and built separately.

## Routes & controller

A new `SettingsController`, both routes behind the existing `auth` middleware
group in `routes/web.php`:

- `GET /settings` → `settings.edit` → `SettingsController@edit`
  Renders the `Settings` Inertia page with `{ email, temperatureUnit }`.
- `PATCH /settings` → `settings.update` → `SettingsController@update`
  Validates `temperature_unit` against the allowed set, saves it to the
  authenticated user, redirects back with a flash confirmation.

### Validation & security

- `temperature_unit` is `required` and must be one of `User::UNIT_FAHRENHEIT`
  (`fahrenheit`) or `User::UNIT_CELSIUS` (`celsius`). Use a form request
  (`UpdateSettingsRequest`) consistent with the existing request classes.
- Only `temperature_unit` is accepted. `email`, `plan`, and `is_admin` are never
  read from input — `plan`/`is_admin` are already non-fillable, and `email` is
  simply not part of this form.

## Page

`resources/js/pages/Settings.vue`, using `AppLayout`. Three calm sections,
matching the existing dashboard visual language (hairline borders,
`surface-raised`, the established text tokens):

1. **Account** — email shown read-only, with a quiet note that it can't be
   changed yet.
2. **Preferences** — temperature unit as a two-option segmented toggle
   (Fahrenheit / Celsius). **Auto-save on change:** flipping the toggle issues an
   optimistic `router.patch` to `settings.update` and shows a success toast
   (`vue-sonner`, already used on the dashboard). On error it reverts to the
   previous unit and shows an error toast. No Save button.
3. **Log out** — the logout action (`POST /logout`, the existing `logout` route),
   moved here from the top bar.

Route helpers come from Wayfinder (`@/routes` / `@/actions`), consistent with the
rest of the app.

## Navigation

`AppLayout`'s top bar currently shows the `tripcast` logo (links to dashboard
via `home`, which now redirects authenticated users to the dashboard) and a Log
out button. Replace the top-bar Log out with a **Settings** link to
`settings.edit`. Log out moves onto the settings page, giving one home for
account actions.

## Testing

Feature tests are the priority (per project rules):

- `GET /settings` redirects a guest to login.
- `GET /settings` for an authenticated user renders the `Settings` page with
  their email and current temperature unit as props.
- `PATCH /settings` with a valid unit persists the change to the user and
  redirects back with a flash status.
- `PATCH /settings` with an invalid unit fails validation and does not change the
  stored unit.
- `PATCH /settings` as a guest redirects to login.

## Implementation notes

- `temperature_unit` is already in the `User` `#[Fillable]` set and has the
  `UNIT_FAHRENHEIT` / `UNIT_CELSIUS` constants — no model or migration changes.
- Follow the dashboard's optimistic-update + toast pattern for the auto-save so
  the interaction feels consistent.
