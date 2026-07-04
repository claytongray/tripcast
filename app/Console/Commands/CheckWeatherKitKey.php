<?php

namespace App\Console\Commands;

use App\Services\Weather\DestinationTimezone;
use App\Services\Weather\WeatherKit\WeatherKitProvider;
use App\Services\Weather\WeatherKit\WeatherKitToken;
use App\Services\Weather\WeatherProviderFailedException;
use Firebase\JWT\JWT;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Preflight for the WeatherKit cutover (Epic 11): confirms the running app can
 * load and use the credentials it would use in production — the four
 * APPLE_WEATHERKIT_* values and, above all, the git-ignored .p8 private key,
 * which does NOT survive a Forge zero-downtime deploy unless it lives outside
 * the release tree (an absolute path or the shared storage/ dir). Read-only and
 * provider-flag-independent: run it AFTER uploading the key but BEFORE flipping
 * TRIPCAST_WEATHER_PROVIDER=weatherkit, so a missing/unreadable key surfaces
 * here instead of as an opaque 401 (or a hard production bind failure) later.
 *
 * Never prints the key or a minted token (only that signing succeeded).
 */
#[Signature('weatherkit:check {--live : Additionally make one live WeatherKit API call to confirm Apple accepts the credentials}')]
#[Description('Preflight the WeatherKit private key + credentials the running app would load (read-only). Run before the provider cutover.')]
class CheckWeatherKitKey extends Command
{
    public function handle(): int
    {
        $this->newLine();
        $this->line('  <options=bold>WeatherKit preflight</> — what THIS app would load');
        $this->line('  running as: '.$this->processUser().'   provider flag: '.config('tripcast.forecast.provider'));
        $this->newLine();

        $kit = config('services.weatherkit');
        $ok = true;

        // 1. The three non-secret identifiers must be present.
        foreach (['team_id', 'service_id', 'key_id'] as $field) {
            $value = $kit[$field] ?? null;
            $present = filled($value);
            $ok = $this->report($present, 'APPLE_WEATHERKIT_'.strtoupper($field), $present ? $this->mask((string) $value) : 'MISSING') && $ok;
        }

        // 2. Resolve the key path exactly as AppServiceProvider does: an absolute
        //    path is used as-is; a relative one resolves against the app root.
        $keyPath = $kit['private_key_path'] ?? null;

        if (! is_string($keyPath) || $keyPath === '') {
            $this->report(false, 'APPLE_WEATHERKIT_PRIVATE_KEY', 'MISSING (no path configured)');

            return $this->finish(false);
        }

        $keyFile = str_starts_with($keyPath, '/') ? $keyPath : base_path($keyPath);
        $this->report(true, 'APPLE_WEATHERKIT_PRIVATE_KEY', $keyPath);
        $this->line('       → resolves to: '.$keyFile);

        // 3. The file must exist in THIS release and be readable by THIS user.
        if (! is_file($keyFile)) {
            $this->report(false, 'file exists', 'NO — not present in this release (did it survive the deploy?)');

            return $this->finish(false);
        }

        $readable = is_readable($keyFile);
        $this->report($readable, 'file readable', $readable ? 'yes' : 'NO — not readable by '.$this->processUser());
        $this->line('       perms '.$this->perms($keyFile).', owner '.$this->owner($keyFile).', '.filesize($keyFile).' bytes');

        if (! $readable) {
            return $this->finish(false);
        }

        // 4. The contents must parse as a private key…
        $pem = (string) file_get_contents($keyFile);
        $parsed = openssl_pkey_get_private($pem) !== false;
        $ok = $this->report($parsed, 'valid PEM private key', $parsed ? 'parses' : 'NO — not a readable PEM private key') && $ok;

        if (! $parsed) {
            return $this->finish(false);
        }

        // 5. …and actually sign the ES256 JWT WeatherKit demands (no cache, no
        //    network) — the definitive proof the key is usable for auth.
        $signs = $this->canSignEs256($pem, (string) ($kit['key_id'] ?? ''));
        $ok = $this->report($signs, 'ES256 signing', $signs ? 'the key signs the WeatherKit JWT' : 'FAILED — key cannot sign ES256') && $ok;

        // 6. Optional end-to-end proof: one real WeatherKit call (opt-in — it
        //    hits Apple and confirms the creds are accepted, not just locally valid).
        if ($this->option('live')) {
            $ok = $this->liveFetch($kit, $pem) && $ok;
        }

        return $this->finish($ok);
    }

    /**
     * Mint one ES256 JWT with the key to prove it can sign WeatherKit auth
     * tokens. Never returns or logs the token itself.
     */
    private function canSignEs256(string $pem, string $keyId): bool
    {
        try {
            JWT::encode(['iss' => 'preflight', 'iat' => 0, 'exp' => 1], $pem, 'ES256', $keyId !== '' ? $keyId : null, ['id' => 'preflight']);

            return true;
        } catch (Throwable $e) {
            $this->line('       '.$e->getMessage());

            return false;
        }
    }

    /**
     * Make one live WeatherKit fetch for a fixed inland coordinate, reporting the
     * first day's high so a human can eyeball a realistic air temp (not a 105°F
     * heat-index inflation). A pinned timezone keeps Google out of the path.
     *
     * @param  array<string, mixed>  $kit
     */
    private function liveFetch(array $kit, string $pem): bool
    {
        $this->newLine();
        $this->line('  --live: calling Apple WeatherKit for Kennett Square (39.8467, -75.7116)…');

        try {
            $token = new WeatherKitToken((string) $kit['team_id'], (string) $kit['service_id'], (string) $kit['key_id'], $pem);
            $provider = new WeatherKitProvider($token, app(DestinationTimezone::class));
            $forecast = $provider->fetchForecast(39.8467, -75.7116, 'America/New_York');
            $day = $forecast->days[0] ?? null;

            if ($day === null) {
                return $this->report(false, 'live WeatherKit fetch', 'FAILED — response carried no forecast days');
            }

            return $this->report(true, 'live WeatherKit fetch', "OK — {$day->date}: high {$day->highF}°F, {$day->conditionText}");
        } catch (WeatherProviderFailedException $e) {
            return $this->report(false, 'live WeatherKit fetch', 'FAILED — '.$e->getMessage());
        }
    }

    private function report(bool $pass, string $label, string $detail): bool
    {
        $mark = $pass ? '<fg=green;options=bold>[PASS]</>' : '<fg=red;options=bold>[FAIL]</>';
        $this->line(sprintf('  %s  %-30s %s', $mark, $label, $detail));

        return $pass;
    }

    private function finish(bool $ok): int
    {
        $this->newLine();

        if ($ok) {
            $this->components->info('WeatherKit preflight PASSED — the app can load and sign with the key. Safe to set TRIPCAST_WEATHER_PROVIDER=weatherkit.');

            return self::SUCCESS;
        }

        $this->components->error('WeatherKit preflight FAILED — fix the item(s) marked [FAIL] before cutover. Do NOT flip the provider yet.');

        return self::FAILURE;
    }

    /**
     * Show enough of a non-secret identifier to recognise it, without printing
     * it whole (e.g. "ABC…YZ").
     */
    private function mask(string $value): string
    {
        if (strlen($value) <= 4) {
            return str_repeat('•', strlen($value));
        }

        return substr($value, 0, 3).'…'.substr($value, -2).' ('.strlen($value).' chars)';
    }

    private function perms(string $file): string
    {
        return substr(sprintf('%o', fileperms($file)), -4);
    }

    private function owner(string $file): string
    {
        $id = fileowner($file);

        if ($id === false) {
            return 'unknown';
        }

        if (function_exists('posix_getpwuid')) {
            $info = posix_getpwuid($id);

            if ($info !== false) {
                return $info['name'];
            }
        }

        return (string) $id;
    }

    private function processUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());

            if ($info !== false) {
                return $info['name'];
            }
        }

        return get_current_user() ?: 'unknown';
    }
}
