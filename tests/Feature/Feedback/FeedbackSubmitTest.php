<?php

use App\Mail\FeedbackMail;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

beforeEach(function () {
    $this->user = User::factory()->create();
    RateLimiter::clear('feedback:'.$this->user->id);
});

it('queues the feedback to the team inbox with the user as reply-to', function () {
    Mail::fake();
    Trip::factory()->count(2)->for($this->user)->create();

    actingAs($this->user)
        ->post(route('feedback.store'), [
            'message' => 'Love the daily digests — a weekly summary would be great too.',
            'source' => 'dashboard',
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    Mail::assertQueued(FeedbackMail::class, fn (FeedbackMail $mail) => $mail->hasTo(config('mail.from.address'))
        && $mail->hasReplyTo($this->user->email)
        && $mail->userMessage === 'Love the daily digests — a weekly summary would be great too.'
        && $mail->source === 'dashboard'
        && $mail->tripCount === 2);
});

it('rejects an empty message', function () {
    Mail::fake();

    actingAs($this->user)
        ->post(route('feedback.store'), ['message' => '', 'source' => 'dashboard'])
        ->assertSessionHasErrors('message');

    Mail::assertNothingQueued();
});

it('rejects a message over 2000 characters', function () {
    Mail::fake();

    actingAs($this->user)
        ->post(route('feedback.store'), [
            'message' => str_repeat('a', 2001),
            'source' => 'nav',
        ])
        ->assertSessionHasErrors('message');

    Mail::assertNothingQueued();
});

it('rejects an unknown source', function () {
    Mail::fake();

    actingAs($this->user)
        ->post(route('feedback.store'), ['message' => 'Hi!', 'source' => 'footer'])
        ->assertSessionHasErrors('source');

    Mail::assertNothingQueued();
});

it('redirects guests to login', function () {
    Mail::fake();

    post(route('feedback.store'), ['message' => 'Hi!', 'source' => 'dashboard'])
        ->assertRedirect(route('login'));

    Mail::assertNothingQueued();
});

it('rejects the fourth submission in an hour with a calm message', function () {
    Mail::fake();

    foreach (range(1, 3) as $attempt) {
        actingAs($this->user)
            ->post(route('feedback.store'), ['message' => "Note {$attempt}", 'source' => 'nav'])
            ->assertSessionDoesntHaveErrors();
    }

    actingAs($this->user)
        ->post(route('feedback.store'), ['message' => 'One more', 'source' => 'nav'])
        ->assertSessionHasErrors('message');

    Mail::assertQueuedCount(3);
});

it('renders the mail views with the message, sender, source, and trip count', function () {
    $mail = new FeedbackMail($this->user, 'More weather detail please.', 'dashboard', 1);

    $rendered = $mail->render();

    expect($rendered)
        ->toContain('More weather detail please.')
        ->toContain($this->user->email)
        ->toContain('dashboard');

    expect($mail->envelope()->subject)->toBe('Feedback from '.$this->user->email);
});
