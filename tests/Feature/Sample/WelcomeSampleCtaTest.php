<?php

use App\Mail\SampleDigestMail;
use App\Models\SampleRequest;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

it('queues the generic sample to the user from a valid signed welcome link', function () {
    Mail::fake();
    $user = User::factory()->confirmed()->create();

    $url = URL::signedRoute('email.sample.send', ['user' => $user->id]);
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
