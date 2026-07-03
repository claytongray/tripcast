# Story 10.1: Site feedback form (dashboard inline + nav modal)

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a signed-in tripcast user,
I want a dead-simple way to send the team my thoughts and ideas from the dashboard (or anywhere via the nav),
so that early feedback is effortless to give and shapes the product while it's young.

## Context & Provenance

- Promoted from the deferred backlog item "Dead-simple user feedback capture (lean-launch listening)" — item (1) site feedback form ([Source: _bmad-output/implementation-artifacts/deferred-work.md:23]). Items (2) post-trip email and (3) thumbs-with-text remain deferred — OUT OF SCOPE here.
- Design approved by Clayton 2026-07-03 in brainstorming. **Deliberate deviation from the deferred note:** NO new model/table — email-only delivery. The deferred note suggested a lightweight table; Clayton chose email-only (YAGNI; queued mail already persists through the database queue; a table can be added later if volume grows).
- New Epic 10 ("Lean-launch listening"), first story. Branch: `feat/feedback-form` off `main`.

## Acceptance Criteria

1. **Given** a signed-in user on the dashboard, **when** the page renders, **then** a feedback card appears **between the "Past trips" section and the "Send a sample" card** (always visible, like the sample card), with heading "Thoughts? Ideas? Please send them.", one warm supporting line about feedback shaping tripcast early on, a ~3-row textarea, and a "Send feedback" button.
2. **Given** a signed-in user anywhere in the authenticated shell, **when** they click a new "Feedback" item in the top nav (a button, not a Link — sits with Admin/Settings), **then** a modal opens containing the same form component.
3. **Given** a non-empty message (≤ 2000 chars), **when** submitted, **then** `POST /feedback` (route `feedback.store`, inside the existing `auth` group) queues a `FeedbackMail` to `config('mail.from.address')` (hello@tripcast.fyi in prod) with **reply-to set to the submitting user's email**, subject `Feedback from {user email}`, and a body containing: the message, the user's email, the source surface (`dashboard` or `nav`), and the user's trip count.
4. **Given** a successful submit, **then** the textarea resets and the form swaps to a warm thank-you state (e.g. "Thank you — this is genuinely valuable to us.") plus a `toast.success`; in the modal, the dialog closes on its own a beat (~1.5–2s) after the thank-you shows.
5. **Given** an empty or > 2000-char message, **when** submitted, **then** a validation error shows under the textarea via `InputError` (error key `message`) and nothing is queued.
6. **Given** a user who has already submitted 3 times in the past hour, **when** they submit a 4th, **then** the request is rejected with a calm `ValidationException` message on the `message` key (no mail queued), mirroring the sample-self limiter.
7. **Given** a guest, **when** they `POST /feedback`, **then** they are redirected to login and nothing is queued.
8. All verification gates pass: `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr`.

## Tasks / Subtasks

- [ ] Task 1: Backend endpoint (AC: 3, 5, 6, 7)
  - [ ] `php artisan make:controller FeedbackController --no-interaction` — single `store(Request $request): RedirectResponse` method
  - [ ] Validate: `message => ['required', 'string', 'max:2000']`, `source => ['required', 'in:dashboard,nav']`
  - [ ] Rate limit BEFORE validation-heavy work, copying `SampleController::storeForSelf` exactly (app/Http/Controllers/SampleController.php:64-91): key `'feedback:'.$user->id`, `RateLimiter::tooManyAttempts($key, 3)` → `ValidationException::withMessages(['message' => "That's a lot of feedback in one sitting — thank you! Give it about an hour and send more."])`, then `RateLimiter::hit($key, 3600)`
  - [ ] Queue mail: `Mail::to(config('mail.from.address'))->queue(new FeedbackMail($user, $validated['message'], $validated['source'], $user->trips()->count()));` then `return back();`
  - [ ] Route in the existing `auth` group in routes/web.php (after `sample/self`, line ~71): `Route::post('feedback', [FeedbackController::class, 'store'])->name('feedback.store');` with a short comment in the file's existing comment voice
- [ ] Task 2: FeedbackMail mailable (AC: 3)
  - [ ] `php artisan make:mail FeedbackMail --no-interaction` in `app/Mail`
  - [ ] Constructor (promotion, public): `User $user`, `string $userMessage`, `string $source`, `int $tripCount`. **Do NOT name the property `$message`** — `Mailable` has internal name-collision hazards with view data named `message` (Laravel reserves it for the rendered message object); use `$userMessage` and pass `userMessage` to the view
  - [ ] `envelope()`: subject `'Feedback from '.$this->user->email`, `replyTo: [new Address($this->user->email)]` (`Illuminate\Mail\Mailables\Address`). **No user name exists** — users table has no `name` column (email-only accounts), see app/Models/User.php docblock
  - [ ] `content()`: text-first internal notification — follow the plain style of resources/views/emails/magic-link-text.blade.php; create `emails/feedback-text.blade.php` (text) and a minimal `emails/feedback.blade.php` (html) listing: message body, from email, source, trip count. This is an internal ops email — no branding, no unsubscribe, no promo (same rationale as SampleDigestMail's "one-off" note)
  - [ ] Not `ShouldQueue` — queueing is the caller's job via `Mail::...->queue()` (project convention, see SampleController.php:44,81 and the DigestMail comment)
- [ ] Task 3: Wayfinder route generation (AC: 3)
  - [ ] After adding the route run `php artisan wayfinder:generate --no-interaction` → produces `@/routes/feedback` with `store`
- [ ] Task 4: FeedbackForm.vue — the reusable core (AC: 1, 4, 5, 6)
  - [ ] New `resources/js/components/FeedbackForm.vue` (sibling of InputError.vue etc.); props: `source: 'dashboard' | 'nav'`; emits: `sent` (parent dialogs use it to schedule close)
  - [ ] `useForm({ message: '', source: props.source })`, submit via `form.post(store().url, { preserveScroll: true, onSuccess ... })` importing `{ store } from '@/routes/feedback'` (same pattern as Dashboard.vue:248-270 / TripDetail.vue useForm)
  - [ ] Markup: `<h2 class="text-subtitle text-ink">Thoughts? Ideas? Please send them.</h2>`, supporting `<p class="text-body text-ink-secondary">` ("tripcast is young — what you tell us now genuinely shapes it. We read every note."), `<textarea rows="3">` styled with the Input.vue class string adapted for textarea (`h-11` → `min-h-20`, keep `bg-card rounded-sm border-input focus-visible:...` etc. — there is NO ui/textarea component; do not add a dependency, style inline or create `components/ui/textarea/` following Input.vue verbatim), `<InputError :message="form.errors.message" />`, submit `<Button variant="outline" size="sm" :disabled="form.processing">Send feedback</Button>`
  - [ ] Success state: local `sent` ref — swap form for a warm thank-you line (`role="status"`), `toast.success('Sent — thank you. Feedback like this shapes tripcast.')` (import `{ toast } from 'vue-sonner'`), `form.reset()`, emit `sent`. Keep the thank-you persistent on the dashboard card (per-visit, like `sampleSent`), no auto-revert
  - [ ] Copy voice: lowercase "tripcast", no "watching", warm not corporate [Source: memory copy-voice-welcome-surfaces]
- [ ] Task 5: Dashboard placement (AC: 1)
  - [ ] In `resources/js/pages/Dashboard.vue`, insert `<section class="...same card classes as the sample card (line 530-532)..."><FeedbackForm source="dashboard" /></section>` — or put the section wrapper inside FeedbackForm and render `<FeedbackForm source="dashboard" />` bare — **between** the Past-trips `</section>` (line 527) and the "Send a sample" section (line 529). The `main` element's `flex flex-col gap-8` supplies the requested padding on both sides automatically — do not add extra margins
- [ ] Task 6: FeedbackDialog.vue + nav item (AC: 2, 4)
  - [ ] New `resources/js/components/FeedbackDialog.vue`: `v-model:open`-style controlled Dialog using `Dialog/DialogContent/DialogHeader/DialogTitle` from `@/components/ui/dialog` (usage pattern: Dashboard.vue:553-578); body is `<FeedbackForm source="nav" @sent="scheduleClose" />`; `scheduleClose` = `setTimeout(() => emit('update:open', false), 1800)`; clear the timer on unmount
  - [ ] `resources/js/layouts/AppLayout.vue`: add a `<button type="button" @click="feedbackOpen = true">Feedback</button>` styled with the exact class string of the Settings `Link` (AppLayout.vue:41-46), placed before Settings in the right-side `div.flex.items-center.gap-1`; mount `<FeedbackDialog v-model:open="feedbackOpen" />` (or `:open` + `@update:open`) next to `<Toaster>`; no auth guard needed in the layout — AppLayout only wraps authenticated pages, and the route is auth-guarded regardless (same reasoning as the Admin link comment, AppLayout.vue:14-15)
- [ ] Task 7: Pest feature test (AC: 3, 5, 6, 7)
  - [ ] `php artisan make:test --pest Feedback/FeedbackSubmitTest --no-interaction` (new tests/Feature/Feedback/ dir mirrors tests/Feature/Sample/)
  - [ ] Mirror tests/Feature/Sample/DashboardSampleTest.php exactly: `beforeEach` creates user + `RateLimiter::clear('feedback:'.$this->user->id)`
  - [ ] Test: queues `FeedbackMail` to `config('mail.from.address')` with `$mail->hasReplyTo($this->user->email)` and correct `userMessage`/`source`/`tripCount` (create trips via `Trip::factory()` for the count)
  - [ ] Test: empty message → `assertSessionHasErrors('message')`, nothing queued; 2001-char message (`str_repeat('a', 2001)`) → same; bad `source` → `assertSessionHasErrors('source')`
  - [ ] Test: guests redirected to login, nothing queued
  - [ ] Test: 3 sends OK, 4th → `assertSessionHasErrors('message')`, `Mail::assertQueuedCount(3)`
- [ ] Task 8: Gates + manual smoke (AC: 8)
  - [ ] Run all six gates from project-context.md; fix fallout. Frontend change needs `npm run build` (or user runs dev) to be visible — ask Clayton, don't assume

## Dev Notes

### Critical guardrails (read first)

- **Naming collision — existing `Feedback` model is NOT this feature.** `app/Models/Feedback.php` + the `feedback` table are the per-digest thumbs reaction (`helped`/`not_helpful`, one row per trip+send_date), fed by signed email links via `EmailAction` (routes/web.php:119-124). Do not touch, reuse, or extend them. This story creates NO model, NO table, NO migration. `FeedbackController` and `FeedbackMail` are new names with no existing class conflicts (existing controller is `EmailAction`).
- **Users have no name.** `users` table: id/email/plan/timezone/is_admin/email_opted_out/temperature_unit — no `name` (magic-link, email-only accounts). Anything "Feedback from {name}" must use the email.
- **Throttle is in-controller, not middleware.** Copy the `storeForSelf` limiter shape (SampleController.php:67-75) with key `feedback:{user_id}`. Do NOT use `->middleware('throttle:...')` — the established dashboard-action pattern surfaces a calm, field-level validation message instead of a 429, and tests key off `assertSessionHasErrors`.
- **Send target:** `config('mail.from.address')` — hello@tripcast.fyi in prod [Source: memory mailersend-setup], `MAIL_FROM_ADDRESS` env elsewhere. No new env var, no hardcoded address.
- **Queue, never send sync:** `Mail::to(...)->queue(...)` (database queue). Mailable itself is not `ShouldQueue`.
- **Mailable `message` hazard:** name the text property `$userMessage`. Laravel injects a `$message` variable into every mail view (the Swift/Symfony message); a `public string $message` property or `'message'` view key collides and breaks embedding/rendering.

### Existing patterns to copy (file:line)

| What | Where |
| --- | --- |
| Limiter + calm ValidationException | app/Http/Controllers/SampleController.php:64-91 |
| Auth route group placement | routes/web.php:49-72 (add after line 71) |
| Mailable shape (envelope/content, caller queues) | app/Mail/SampleDigestMail.php |
| Plain internal-style mail view | resources/views/emails/magic-link-text.blade.php |
| useForm + InputError + Label form | resources/js/pages/Dashboard.vue:317-399 |
| post + toast onSuccess/onError | resources/js/pages/Dashboard.vue:248-270 |
| Card section styling (the card to visually match) | resources/js/pages/Dashboard.vue:529-549 |
| Dialog composition | resources/js/pages/Dashboard.vue:553-578 |
| Nav link class string (reuse verbatim on the button) | resources/js/layouts/AppLayout.vue:41-46 |
| Input field classes (adapt for textarea) | resources/js/components/ui/input/Input.vue |
| Wayfinder import style | Dashboard.vue:21-22 (`import { self as sampleSelf } from '@/routes/sample'`) |
| Throttle test shape | tests/Feature/Sample/DashboardSampleTest.php |

### Architecture & stack constraints

- Laravel 13 / PHP 8.3, Inertia v3, Vue 3, Tailwind v4, Pest 4, Wayfinder. Strict types + return types on all PHP methods; constructor property promotion; curly braces always [Source: CLAUDE.md php rules].
- PHPStan (larastan) runs at max signal — plain controller/mailable code here has no known gotcha exposure (no raw SQL, no dynamic aggregates), but keep `@param`/`@property` hygiene [Source: _bmad-output/planning-artifacts/project-context.md].
- Design tokens only (`text-ink`, `text-ink-secondary`, `border-hairline`, `bg-surface-raised`, `text-subtitle`, `text-body`, `text-meta`) — no raw Tailwind palette colors. Match the sample card exactly for the dashboard card.
- Vue components: single root element; `<script setup lang="ts">`; props/emit types declared.
- SSR builds (`npm run build:ssr`) — no `window`/`document` at module scope in new components; `setTimeout` inside event handlers is fine.

### What NOT to do

- No new DB table/model/migration (approved deviation from deferred-work.md — email-only).
- No floating widget or `/feedback` page (deferred note mentions them; this story is dashboard card + nav modal only).
- No guest/anonymous submission (deferred note wanted login-free; Clayton's approved design is auth-only — the route lives in the `auth` group).
- No new npm/composer dependencies.
- Don't render the feedback card on the landing page or settings — dashboard + nav modal only.

### Previous story intelligence

- Epic 9 stories (9.9/9.10, status review/done) most recently touched mail plumbing: MailerSend rejects custom List-Unsubscribe headers on the current plan — irrelevant here (internal mail, no such header) but do NOT add bulk-mail headers to FeedbackMail.
- Recent commits (eba34e3) cached the sample forecast as a plain array — reminder of the project rule: never cache PHP objects in Redis [Source: docs/deployment.md]. No caching in this story.
- Toaster is mounted once in AppLayout (line 59) — `toast.*` from any child works; don't mount a second `<Toaster>`.

### Testing standards

- Pest 4 feature tests, `RefreshDatabase` via tests/Pest.php defaults; factories for models; `Mail::fake()` before acting; clear the limiter key in `beforeEach` (limiter state persists across tests in the same process — this bit DashboardSampleTest first).
- Run narrow: `php artisan test --compact tests/Feature/Feedback/FeedbackSubmitTest.php`, then the full gate set before marking done.

### Project Structure Notes

- `app/Http/Controllers/FeedbackController.php` — NEW
- `app/Mail/FeedbackMail.php` — NEW
- `resources/views/emails/feedback.blade.php`, `feedback-text.blade.php` — NEW
- `resources/js/components/FeedbackForm.vue`, `FeedbackDialog.vue` — NEW (components root, beside InputError.vue)
- `resources/js/routes/feedback/` — GENERATED (wayfinder)
- `routes/web.php` — UPDATE (one route in the auth group)
- `resources/js/pages/Dashboard.vue` — UPDATE (one section insert between lines 527/529; imports)
- `resources/js/layouts/AppLayout.vue` — UPDATE (nav button + dialog mount; keep the calm-shell comment voice)
- `tests/Feature/Feedback/FeedbackSubmitTest.php` — NEW

### References

- [Source: _bmad-output/implementation-artifacts/deferred-work.md:23 — origin backlog item]
- [Source: _bmad-output/planning-artifacts/project-context.md — verification gates, test gotchas]
- [Source: app/Http/Controllers/SampleController.php#storeForSelf — throttle authority]
- [Source: tests/Feature/Sample/DashboardSampleTest.php — test template]
- [Source: CLAUDE.md / docs/deployment.md — push-to-main auto-deploys; keep this branch off main until reviewed]

## Dev Agent Record

### Agent Model Used

claude-fable-5 (create-story context engine)

### Debug Log References

### Completion Notes List

- Ultimate context engine analysis completed - comprehensive developer guide created
- Story created from an approved brainstorming design (2026-07-03), not from epics.md — Epic 10 registered directly in sprint-status.yaml

### File List
