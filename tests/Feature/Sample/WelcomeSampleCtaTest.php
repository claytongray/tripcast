<?php

use App\Mail\SampleDigestMail;
use App\Models\SampleRequest;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

it('queues the generic sample to the user from a valid temporary-signed welcome link', function () {
    Mail::fake();
    $user = User::factory()->confirmed()->create();

    $url = url(URL::temporarySignedRoute('email.sample.send', now()->addMinutes(2880), ['user' => $user->id], absolute: false));
    $this->get($url)->assertOk();

    Mail::assertQueued(SampleDigestMail::class, fn (SampleDigestMail $m) => $m->hasTo($user->email));
    expect(SampleRequest::where('user_id', $user->id)->where('source', SampleRequest::SOURCE_LANDING)->exists())->toBeTrue();
});

it('rejects an unsigned or tampered welcome sample link', function () {
    Mail::fake();
    $user = User::factory()->confirmed()->create();

    $this->get(route('email.sample.send', ['user' => $user->id]))->assertForbidden();

    Mail::assertNothingQueued();
});

it('rejects an expired welcome sample link', function () {
    Mail::fake();
    $user = User::factory()->confirmed()->create();

    $url = url(URL::temporarySignedRoute('email.sample.send', now()->subMinute(), ['user' => $user->id], absolute: false));
    $this->get($url)->assertForbidden();

    Mail::assertNothingQueued();
});

it('rate-limits the welcome sample to 3 sends per user per hour', function () {
    Mail::fake();
    $user = User::factory()->confirmed()->create();

    $url = url(URL::temporarySignedRoute('email.sample.send', now()->addMinutes(2880), ['user' => $user->id], absolute: false));

    for ($i = 0; $i < 4; $i++) {
        $this->get($url)->assertOk();
    }

    // Only the first 3 hits send a mail and write a row; the 4th is absorbed silently.
    Mail::assertQueued(SampleDigestMail::class, 3);
    expect(SampleRequest::where('user_id', $user->id)->count())->toBe(3);
});
