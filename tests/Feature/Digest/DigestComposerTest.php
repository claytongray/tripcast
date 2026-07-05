<?php

use App\Models\Trip;
use App\Models\User;
use App\Services\Digest\ComposedDigest;
use App\Services\Digest\DigestComposer;
use App\Services\Promo\Promo;
use App\Services\Promo\PromoProvider;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-29 09:05:00', 'America/New_York'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function composerSnapshot(): array
{
    return ['days' => [['date' => '2026-06-29', 'conditionText' => 'Sunny', 'precipChance' => 10, 'highC' => 20.0, 'highF' => 68.0, 'lowC' => 12.0, 'lowF' => 53.6]], 'limited' => true];
}

it('wires the snapshot into a DigestMail and selects a promo for free-plan users', function () {
    $trip = Trip::factory()->for(User::factory()->confirmed()->create())->create();
    $promo = new Promo('rain-jacket', 'Rain jacket', null, 'https://example.test/j');

    $this->mock(PromoProvider::class)
        ->shouldReceive('select')->once()->andReturn($promo);

    $composed = app(DigestComposer::class)->compose($trip, composerSnapshot(), '2026-06-29');

    expect($composed)->toBeInstanceOf(ComposedDigest::class)
        ->and($composed->mail->snapshot)->toBe(composerSnapshot())
        ->and($composed->promo)->toBe($promo);
});

it('selects no promo for ad-free users (entitlement gate, AD-19)', function () {
    $trip = Trip::factory()->for(User::factory()->confirmed()->adFree()->create())->create();

    $this->mock(PromoProvider::class)->shouldNotReceive('select');

    $composed = app(DigestComposer::class)->compose($trip, composerSnapshot(), '2026-06-29');

    expect($composed->promo)->toBeNull();
});
