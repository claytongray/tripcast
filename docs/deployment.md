# Deployment (Laravel Forge)

Production runs on **Laravel Forge** (not Laravel Cloud) at tripcast.fyi.
**Every push to `origin/main` auto-deploys to production.**

## The deploy script (Forge site → Deployments)

```bash
# Ensure the build uses Node 22, not the box default
  export NVM_DIR="$HOME/.nvm"
  [ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
  nvm use 22 >/dev/null 2>&1 || nvm install 22


$CREATE_RELEASE()

cd $FORGE_RELEASE_DIRECTORY

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

npm ci || npm install
npm run build:ssr
$FORGE_PHP artisan optimize
$FORGE_PHP artisan storage:link
$FORGE_PHP artisan migrate --force

$ACTIVATE_RELEASE()

$RESTART_QUEUES()

$FORGE_PHP artisan inertia:stop-ssr
```

## What happens on every push, in order

1. **Node 22** selected via nvm (the box default is older).
2. **`$CREATE_RELEASE()`** — Forge builds a fresh release directory (atomic,
   release-based deploys; old releases are purged after activation).
3. **Composer** install, no-dev, optimized autoloader.
4. **`npm run build:ssr`** — builds BOTH client and SSR bundles. The site's
   "Inertia SSR" toggle is ON; building only the client bundle crash-loops the
   SSR daemon with "Inertia SSR bundle not found".
5. **`artisan optimize`** — config/route/view caches baked into the release.
6. **`migrate --force`** — migrations run on every deploy, **before**
   activation. Old code briefly serves against the new schema, so additive
   migrations are safe; destructive ones need a two-step deploy.
7. **`$ACTIVATE_RELEASE()`** — symlink switch + old-release purge.
8. **`$RESTART_QUEUES()`** — cycles Forge-managed queue workers.
9. **`inertia:stop-ssr`** — stops the SSR daemon; Forge supervises it back up
   on the new release.

## Known gaps and incident history (2026-07-02/03)

- **No php-fpm reload — and none is needed.** Forge's own note above the
  script ("For zero-downtime deployments, it is unnecessary to reload the
  PHP-FPM service") is correct: nginx passes the resolved release path per
  request (`$realpath_root`), so fpm workers serve consistent code without a
  reload. Do NOT add a reload block. The 2026-07-03
  `__PHP_Incomplete_Class` incident (a Redis-cached serialized `Forecast`
  whose class couldn't be resolved at read time) was real, but its stack
  trace ran entirely in the current release — the writer was never
  positively identified; prime suspects are processes running *outside*
  Forge's release management (e.g., a hand-started worker or artisan run
  pinned to an old `/releases/<id>/` path instead of `/current`).
- **Queue worker restart is only as good as Forge's management of the worker.**
  `$RESTART_QUEUES()` cycles workers registered under the site's Workers tab.
  On 2026-07-02 the worker did not restart on deploy (it was set up outside
  that management) and silently processed nothing — mail stopped with no
  errors anywhere. If email goes quiet after a deploy, check the worker first.
  Any hand-run long-lived process must be launched from `/current`, never a
  pinned `/releases/<id>/` path — pinned processes go stale on every deploy.
- **Never cache PHP objects in Redis.** A serialized app-class blob is only
  readable by a process that can autoload that exact class — any
  out-of-release process can poison or misread it (`__PHP_Incomplete_Class`,
  2026-07-03). Cache plain arrays/scalars (see `SampleForecast` — cached as
  `toArray()`, rehydrated via `fromArray()`; non-array entries treated as a
  cache miss so poisoned keys self-heal).

## Related facts

- Queue: Forge worker, connection `redis`, queue `default`, 1 process,
  timeout 60 (must stay below `retry_after` 90). `REDIS_CLIENT=predis`.
- Env vars live in the Forge site dashboard (Environment editor). Go-live
  checklist: bottom of `.env.example`.
- Weather provider (Epic 11): the active adapter is `TRIPCAST_WEATHER_PROVIDER`
  (`weatherapi` default → `weatherkit` at cutover). Cutover is an **env-only**
  change — set `TRIPCAST_WEATHER_PROVIDER=weatherkit` plus the four
  `APPLE_WEATHERKIT_*` keys in the Forge Environment editor, and upload the
  `.p8` key file to the path named by `APPLE_WEATHERKIT_PRIVATE_KEY`. **The key
  is git-ignored, so it does NOT survive a zero-downtime deploy inside the
  release tree** — a fresh `git` checkout builds each `releases/<id>/`, and only
  `vendor`/`node_modules` (rebuilt) and `.env`/`storage` (symlinked from the
  persistent site root) carry over. Put the key somewhere that persists across
  releases and point `APPLE_WEATHERKIT_PRIVATE_KEY` at it:
  - **relative, in shared storage** (recommended): upload to
    `storage/app/weatherkit-private-key.p8` (NOT `storage/app/public/` — that's
    web-served) and set `APPLE_WEATHERKIT_PRIVATE_KEY=storage/app/weatherkit-private-key.p8`;
    `storage` is symlinked into every release, so the relative path resolves and
    persists, and matches local dev; or
  - **absolute, outside the app**: upload to e.g.
    `/home/forge/tripcast.fyi/weatherkit-private-key.p8` and set the env var to
    that absolute path (the binding uses an absolute path as-is). `chmod 600`,
    owner `forge`.
- **Preflight before flipping the flag:** after uploading the key + setting the
  four `APPLE_WEATHERKIT_*` values (provider still `weatherapi`), run
  `php artisan weatherkit:check` on the server — it confirms the running app can
  find, read, and ES256-sign with the key (add `--live` for one real Apple call).
  Only flip `TRIPCAST_WEATHER_PROVIDER=weatherkit` once it reports PASS. No
  redeploy is needed for the flag; it renders the Apple Weather attribution the
  WeatherKit license mandates. Keep `WEATHERAPI_KEY` set so a flip back to
  `weatherapi` is instant.
- Daily digests: scheduler cron runs `schedule:run`; `digests:send` at 9am
  America/New_York with a healthchecks.io heartbeat (grace 30m).
