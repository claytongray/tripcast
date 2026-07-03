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

- **No php-fpm reload in the script.** Stale fpm workers survive the release
  switch holding the old release's autoloader, whose paths point into purged
  directories — any class they haven't loaded yet then fails to autoload.
  This turned a Redis-cached serialized `Forecast` object into
  `__PHP_Incomplete_Class` and 500'd sample sends for a day (2026-07-03).
  Planned fix: add Forge's stock reload block right after `$ACTIVATE_RELEASE()`:

  ```bash
  ( flock -w 10 9 || exit 1
      echo 'Reloading PHP FPM...'
      sudo -S service $FORGE_PHP_FPM reload ) 9>/tmp/fpmlock
  ```

  Until it's added: unexplained one-off errors right after a deploy are
  probably stale fpm workers — reload fpm before deep-debugging.
- **Queue worker restart is only as good as Forge's management of the worker.**
  `$RESTART_QUEUES()` cycles workers registered under the site's Workers tab.
  On 2026-07-02 the worker did not restart on deploy (it was set up outside
  that management) and silently processed nothing — mail stopped with no
  errors anywhere. If email goes quiet after a deploy, check the worker first.
- **Never cache PHP objects in Redis.** Release purges + stale workers make
  serialized app classes unresolvable at read time. Cache plain arrays/scalars
  (see `SampleForecast` — cached as `toArray()`, rehydrated via `fromArray()`).

## Related facts

- Queue: Forge worker, connection `redis`, queue `default`, 1 process,
  timeout 60 (must stay below `retry_after` 90). `REDIS_CLIENT=predis`.
- Env vars live in the Forge site dashboard (Environment editor). Go-live
  checklist: bottom of `.env.example`.
- Daily digests: scheduler cron runs `schedule:run`; `digests:send` at 9am
  America/New_York with a healthchecks.io heartbeat (grace 30m).
