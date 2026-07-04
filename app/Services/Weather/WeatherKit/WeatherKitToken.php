<?php

namespace App\Services\Weather\WeatherKit;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;

/**
 * Builds and caches the ES256 JWT WeatherKit requires on every request. The
 * header carries the non-standard `id` field (`<team>.<service>`) that Apple
 * demands — the #1 cause of 401s — alongside the standard `kid`. The token is
 * cached (keyed by the signing key id) and reused until shortly before expiry;
 * WeatherKit accepts one token across many requests within its window.
 */
class WeatherKitToken
{
    private const TTL_SECONDS = 3000; // < the 3600 Apple ceiling

    public function __construct(
        private string $teamId,
        private string $serviceId,
        private string $keyId,
        private string $privateKeyPem,
    ) {}

    public function bearer(): string
    {
        return Cache::remember("weatherkit:jwt:{$this->keyId}", self::TTL_SECONDS, function (): string {
            $now = time();

            return JWT::encode(
                ['iss' => $this->teamId, 'sub' => $this->serviceId, 'iat' => $now, 'exp' => $now + 3600],
                $this->privateKeyPem,
                'ES256',
                $this->keyId,
                ['id' => "{$this->teamId}.{$this->serviceId}"],
            );
        });
    }
}
