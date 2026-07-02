<?php

use App\Mail\SampleDigestMail;
use App\Models\LoginToken;
use App\Models\SampleRequest;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

beforeEach(function () {
    $this->user = User::factory()->create();
    RateLimiter::clear('sample-self:'.$this->user->id);
});

it('queues the sample to the signed-in user with a dashboard CTA', function () {
    Mail::fake();

    actingAs($this->user)
        ->post(route('sample.self'))
        ->assertRedirect();

    Mail::assertQueued(SampleDigestMail::class, fn (SampleDigestMail $mail) => $mail->hasTo($this->user->email)
        && $mail->getStartedUrl === route('dashboard'));
});

it('records no acquisition row and issues no magic link', function () {
    Mail::fake();

    actingAs($this->user)->post(route('sample.self'));

    expect(SampleRequest::count())->toBe(0)
        ->and(LoginToken::count())->toBe(0);
});

it('redirects guests to login', function () {
    Mail::fake();

    post(route('sample.self'))->assertRedirect(route('login'));

    Mail::assertNothingQueued();
});

it('rejects the fourth request in an hour with a calm message', function () {
    Mail::fake();

    foreach (range(1, 3) as $attempt) {
        actingAs($this->user)->post(route('sample.self'))->assertSessionDoesntHaveErrors();
    }

    actingAs($this->user)
        ->post(route('sample.self'))
        ->assertSessionHasErrors('sample');

    Mail::assertQueuedCount(3);
});
