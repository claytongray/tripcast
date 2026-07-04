<?php

use App\Services\Weather\WeatherKit\WeatherKitToken;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function throwawayEcKey(): array
{
    $res = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($res, $private);

    return [$private, openssl_pkey_get_details($res)['key']];
}

it('mints an ES256 JWT with the id header and iss/sub claims', function () {
    [$private, $public] = throwawayEcKey();
    $token = new WeatherKitToken('TEAM123456', 'com.example.app', 'KEY1234567', $private);

    $jwt = $token->bearer();

    $header = json_decode(base64_decode(strtr(explode('.', $jwt)[0], '-_', '+/')), true);
    expect($header['alg'])->toBe('ES256')
        ->and($header['kid'])->toBe('KEY1234567')
        ->and($header['id'])->toBe('TEAM123456.com.example.app');

    $claims = (array) JWT::decode($jwt, new Key($public, 'ES256'));
    expect($claims['iss'])->toBe('TEAM123456')
        ->and($claims['sub'])->toBe('com.example.app')
        ->and($claims['exp'])->toBeGreaterThan($claims['iat']);
});

it('reuses the cached token within its lifetime', function () {
    [$private] = throwawayEcKey();
    $token = new WeatherKitToken('TEAM123456', 'com.example.app', 'KEY1234567', $private);

    // ECDSA signatures are non-deterministic; identical output proves caching.
    expect($token->bearer())->toBe($token->bearer());
});
