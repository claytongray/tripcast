---
baseline_commit: 2bbd2f8
---

# Story 9.9: Digest sends on the current MailerSend plan

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the builder,
I want the daily digest accepted by MailerSend without custom List-Unsubscribe headers,
so that launch doesn't require a Professional-plan upgrade the sending volume doesn't justify.

## Acceptance Criteria

**AC1 — No custom List-Unsubscribe headers on DigestMail** *(FR-27)*
- **Given** the daily `DigestMail`
- **When** it is built
- **Then** it sets no custom `List-Unsubscribe` / `List-Unsubscribe-Post` headers — MailerSend's plan-included managed header covers the mail-client unsubscribe affordance — and a test pins the absence so the headers can't silently return and re-422 production sends (`#MS42235`).

**AC2 — Signed unsubscribe surfaces unchanged** *(FR-27)*
- **Given** the signed unsubscribe surfaces
- **When** the headers are removed
- **Then** the body-link unsubscribe in the footer (FR-5, AD-6) is unchanged, and the signed one-click POST endpoint (`email.unsubscribe.one_click`) stays live with its tests — it is the re-enable path for when the custom header returns on a higher plan.

**AC3 — Real send accepted** *(FR-27, NFR-2)*
- **Given** MailerSend
- **When** a real digest is sent (`php artisan digests:preview --email=…`)
- **Then** the API accepts it (202 — no `#MS42235` 422).

**AC4 — Re-enable path recorded** *(FR-27)*
- **And** `deferred-work.md` records the re-enable path — custom RFC 8058 header + Professional plan + a suppression-webhook sync — triggered when volume approaches the Gmail/Yahoo 5,000/day bulk-sender threshold.

## Why this story exists (decision record — read first)

The daily `DigestMail` currently 422s on every MailerSend send (`#MS42235 This feature requires a higher plan`) because it sets custom RFC 8058 headers and custom headers are gated to Professional ($25/mo)/Enterprise. The original stance (FR-27, Story 9.6 AC3, retro action A2) was "upgrade the plan; the header must not be dropped." That stance was **reversed 2026-07-02** on two facts:

1. **MailerSend support confirmed (2026-07-02):** the Hobby plan does NOT support custom List-Unsubscribe headers — Professional/Enterprise only — **but MailerSend adds and manages its own List-Unsubscribe header by default on every plan** to keep senders compliant. (API sends with custom headers 422; SMTP would silently strip them — not a workaround.)
2. **The Gmail/Yahoo RFC 8058 one-click mandate only binds bulk senders** — 5,000+ messages/day to their consumer inboxes (in force since Feb 2024; Google enforcement escalated Nov 2025). tripcast at launch is orders of magnitude below that. Below the threshold the custom header is a deliverability optimization, not a requirement; what actually matters is the body unsubscribe link (CAN-SPAM — exists, FR-5), SPF/DKIM/DMARC (Story 9.6 Task 4), and a low spam rate.

So: **drop the custom headers, keep the signed body-link unsubscribe as the primary user path, let MailerSend's managed header ride along, stay on the free/Hobby plan.** Epics.md FR-27 and Story 9.6 AC3 were amended 2026-07-02 to match; this story implements the change.

## Tasks / Subtasks

- [x] **Task 1 — Remove the custom headers from `DigestMail`** (AC: 1, 2)
  - [x] Delete `DigestMail::headers()` (`app/Mail/DigestMail.php:62-71`) — the method exists solely to set the two custom headers.
  - [x] Delete the now-unused private `oneClickUnsubscribeUrl()` (`app/Mail/DigestMail.php:150-157`) — its only caller is `headers()`. (PHPStan will flag it if left.) The `content()` method's `unsubscribeUrl` (`email.unsubscribe` body link, line 110) is a DIFFERENT route — do not touch it.
  - [x] Update the class docblock: note that custom List-Unsubscribe headers were removed 2026-07-02 (MailerSend plan gate `#MS42235`; MailerSend injects its managed header at send time; re-enable path in deferred-work.md).
- [x] **Task 2 — Replace the header test with an absence guard** (AC: 1)
  - [x] In `tests/Feature/Digest/DigestMailTest.php:352-361`, replace `it('carries the List-Unsubscribe one-click headers')` with an absence guard: build the mailable, assert its headers carry **no** `List-Unsubscribe` and **no** `List-Unsubscribe-Post` text header (re-adding them on the current plan re-422s every production digest). Keep the Story 2.5 comment block but update it to record the reversal.
  - [x] Do NOT touch `tests/Feature/Email/EmailActionTest.php` — its AC4 block tests the one-click POST endpoint itself, which stays live (AC2).
- [x] **Task 3 — Annotate the dormant surfaces (comments only, no behavior)** (AC: 2)
  - [x] `routes/web.php:83` — the one-click route comment says the header points at it; amend: no header references it since 2026-07-02, kept as the re-enable path (and it still opt-outs anyone who POSTs it with a valid signature).
  - [x] `app/Http/Controllers/EmailAction.php:19,71` — same amendment to the docblock/comment mentions of `List-Unsubscribe-Post`.
  - [x] `config/tripcast.php:192-209` (`unsubscribe_mailto`) — keep the key (smallest blast radius; `.env.example`'s commented `TRIPCAST_UNSUBSCRIBE_MAILTO` line and the 9.6 checklist stay untouched) but annotate it dormant: only consumer was the removed header's mailto arm.
- [x] **Task 4 — Record the re-enable path in `deferred-work.md`** (AC: 4)
  - [x] Add an entry: restore the custom RFC 8058 `List-Unsubscribe` (signed HTTPS one-click + mailto arm) + `List-Unsubscribe-Post` headers on the MailerSend **Professional** plan when volume approaches the Gmail/Yahoo 5,000/day bulk-sender threshold; at the same time add a **MailerSend suppression-webhook sync** (unsubscribes via MailerSend's managed header land in *their* suppression list, not `users.email_opted_out` — tripcast would keep dispatching digests MailerSend silently suppresses; acceptable drift at launch volume because the body link is the primary path users see). Reference: `git log` for this story restores `headers()`/`oneClickUnsubscribeUrl()` verbatim.
- [x] **Task 5 — Gates + real-send verification** (AC: 3)
  - [x] Gates: `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`.
  - [x] Real send: `php artisan digests:preview --email=claytonjgray@gmail.com` (local `.env` is already on `MAIL_MAILER=mailersend`; the command sends a real `DigestMail` with no DB writes and refuses to run in production). Expect **accepted, no `#MS42235` 422**. This was the exact repro of the failure, so it is the proof of the fix.
  - [x] Cross-story note: this unblocks Story 9.6 Task 5's verification arm — after this lands, 9.6's runbook re-check is just "real `DigestMail` send accepted + inbox placement re-confirmed".

## Dev Notes

### Exact current state (recon 2026-07-02, all verified)

- **The headers:** `app/Mail/DigestMail.php:62-71` — `headers()` returns `new Headers(text: ['List-Unsubscribe' => '<'.signed one-click URL.'>, <mailto:'.config('tripcast.unsubscribe_mailto').'?subject=unsubscribe>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'])`.
- **The URL helper:** `oneClickUnsubscribeUrl()` (`app/Mail/DigestMail.php:150-157`) → `URL::signedRoute('email.unsubscribe.one_click', ['user' => $this->trip->user->id])`. Only caller: `headers()`.
- **Two distinct unsubscribe routes — do not confuse them:**
  - `email.unsubscribe` — the confirm-gated **body link** in the digest footer (`content()` `with['unsubscribeUrl']`, line 110). **Unchanged by this story.**
  - `email.unsubscribe.one_click` — the direct **POST** target for the RFC 8058 header (`routes/web.php:83`, `EmailAction.php:71`). Loses its caller but **stays live** (AC2).
- **The failing test after Task 1:** `DigestMailTest.php:352-361` asserts both headers present — flips to the absence guard (Task 2).
- **The 422:** MailerSend API rejects custom headers on Free/Hobby/Starter with `#MS42235`; `SampleDigestMail` has no `headers()` method, which is why the sample always sent fine and only the daily digest is blocked.
- **`digests:preview`** (`app/Console/Commands/PreviewDigests.php`) sends a **real `DigestMail`** (line 78) with an unsaved trip — no DB writes, production-refused. It is both the repro and the verification.

### Guardrails

- **Scope is surgical:** `DigestMail.php` (delete two methods + docblock), one test replaced, three comment annotations, one deferred-work entry. No route, controller, config-value, migration, or Vue changes. Resist any temptation to "clean up" the one-click endpoint or the `unsubscribe_mailto` config — they are deliberate keeps (re-enable path).
- **Do not touch** `.env.example` or `tests/Feature/Ops/EnvExampleChecklistTest.php` (Story 9.6's rot-proof checklist guard) — `TRIPCAST_UNSUBSCRIBE_MAILTO` there is a commented-out optional and stays.
- **Mailable header assertion pattern:** the old test called `$mailable->headers()` directly — that method won't exist after Task 1, so the replacement must assert at the built-message level, which is also the stronger guard (it checks what MailerSend actually sees, regardless of implementation). Use the array mailer (`phpunit.xml` forces `MAIL_MAILER=array`): `Mail::to(...)->send($mailable)`, pull the sent Symfony `Email` from the array transport, then assert `$email->getHeaders()->has('List-Unsubscribe')` and `$email->getHeaders()->has('List-Unsubscribe-Post')` are both **false**.
- **Precedent for the decision trail:** memory + support email say custom headers = Professional/Enterprise; MailerSend manages a default header on all plans (support, 2026-07-02); Gmail/Yahoo threshold facts per Google sender guidelines (5k/day, Feb 2024, enforcement escalated Nov 2025).

### Previous story intelligence (9.6 — directly adjacent)

- 9.6 is `in-progress`, HALTed on its external runbook; its Task 5 was "resolve the gate (upgrade vs built-in)" — **this story IS the resolution.** After landing, 9.6 Task 5 reduces to re-running the real-send check and re-confirming inbox placement (its Task 4 sequencing note).
- Retro action **A2** (`epic-1-retro-A2-mailersend-plan-upgrade` in sprint-status.yaml action_items) is superseded by this story — sprint-status updated 2026-07-02.
- 9.6 recon confirmed: no boot-time guard exists for `MAILERSEND_API_KEY` (fails at send time) — irrelevant here but explains why the 422 only appears on real sends.

### Testing standards

- Pest feature test, array transport (forced by `phpunit.xml`), no mocks of MailerSend — the plan gate is untestable locally by design; that's what the Task 5 real send is for. Follow existing `DigestMailTest.php` construction patterns (factories, snapshot fixture helpers already in that file).

### Project Structure Notes

- **Modified:** `app/Mail/DigestMail.php`, `tests/Feature/Digest/DigestMailTest.php`, `routes/web.php` (comment), `app/Http/Controllers/EmailAction.php` (comments), `config/tripcast.php` (comment), `_bmad-output/implementation-artifacts/deferred-work.md`.
- **New:** none. **Deleted:** none.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-9.9, #FR-27 (amended 2026-07-02), #Story-9.6 AC3 (amended 2026-07-02)]
- [Source: app/Mail/DigestMail.php:62-71, 110, 150-157; routes/web.php:83; app/Http/Controllers/EmailAction.php:19,71; config/tripcast.php:192-209]
- [Source: tests/Feature/Digest/DigestMailTest.php:352-361; tests/Feature/Email/EmailActionTest.php:116]
- [Source: app/Console/Commands/PreviewDigests.php:78 (real DigestMail, no DB writes, production-refused)]
- [Source: MailerSend support email 2026-07-02 (custom List-Unsubscribe = Professional/Enterprise; managed header on all plans); https://www.mailersend.com/help/how-to-add-a-custom-unsubscribe-header; https://www.mailersend.com/help/custom-headers (API 422 vs SMTP strip); Google sender guidelines (5,000/day bulk threshold)]
- [Source: _bmad-output/implementation-artifacts/9-6-production-go-live-deliverability.md (Task 5, blockers); sprint-status.yaml action_items A2]

## Dev Agent Record

### Agent Model Used

claude-fable-5

### Debug Log References

- Red→green: the new absence guard failed while `headers()` existed ("Failed asserting that true is false" on `has('List-Unsubscribe')`), passed after removal. First draft used `Mail::sent()` (fake-only API) — switched to the array transport per the story guardrail.

### Completion Notes List

- **Headers removed:** `DigestMail::headers()` + `oneClickUnsubscribeUrl()` deleted, `Headers` import dropped, class docblock records the 2026-07-02 decision. The body-link `unsubscribeUrl` (`email.unsubscribe`) untouched.
- **Absence guard** replaces the Story 2.5 presence test at the built-message level: sends through the array transport and asserts the Symfony message carries neither `List-Unsubscribe` nor `List-Unsubscribe-Post` — pins what MailerSend actually sees.
- **Dormant surfaces annotated** (comments only): one-click route (`routes/web.php`), `EmailAction` class + method docblocks, `config/tripcast.php` `unsubscribe_mailto`. Endpoint + its `EmailActionTest` coverage stay live as the re-enable path (AC2).
- **Re-enable recipe recorded** in deferred-work.md: Professional plan + restore both methods from this story's git history + MailerSend suppression-webhook sync (managed-header unsubscribes land in MailerSend's suppression list, not `users.email_opted_out`) + flip the guard back to presence.
- **AC3 real-send proof:** `php artisan digests:preview --email=claytonjgray@gmail.com` — **3 real DigestMails accepted by MailerSend, no #MS42235 422** (this exact command was the failure repro).
- Gates: full suite **492 passed** (1974 assertions), pint clean, phpstan 0 errors.
- **Unblocks Story 9.6 Task 5's verification arm:** the accepted real send just happened; the builder's remaining runbook item is re-confirming inbox placement (9.6 Task 4 sequencing note).

### File List

**Modified:**
- `app/Mail/DigestMail.php` (headers()/oneClickUnsubscribeUrl() removed, docblock)
- `tests/Feature/Digest/DigestMailTest.php` (presence test → built-message absence guard)
- `routes/web.php` (comment), `app/Http/Controllers/EmailAction.php` (comments), `config/tripcast.php` (comment)
- `_bmad-output/implementation-artifacts/deferred-work.md` (re-enable path entry)

### Change Log

- 2026-07-02 — Story 9.9: dropped DigestMail's custom List-Unsubscribe/List-Unsubscribe-Post headers (MailerSend plan gate #MS42235; managed header covers all plans, volume far below the Gmail/Yahoo 5k/day bulk threshold). Absence guard added at the built-message level; one-click endpoint kept live + annotated dormant; re-enable path (Professional + suppression-webhook sync) recorded. Real send verified: 3 DigestMails accepted, no 422. Suite 492 passed.
