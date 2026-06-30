<?php

use App\Mail\ConditionsPreviewMail;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

it('emails one condition-icon reference sheet to the given mailbox', function () {
    $this->artisan('digests:conditions', ['--email' => 'me@example.test'])->assertSuccessful();

    Mail::assertSent(ConditionsPreviewMail::class, 1);
    Mail::assertSent(ConditionsPreviewMail::class, fn (ConditionsPreviewMail $m) => $m->hasTo('me@example.test'));
});

it('maps every catalog condition to an icon (none left blank)', function () {
    $this->artisan('digests:conditions')->assertSuccessful();

    Mail::assertSent(ConditionsPreviewMail::class, function (ConditionsPreviewMail $m) {
        // The published catalog has 60 daytime conditions; every one resolves an emoji.
        $blank = array_filter($m->conditions, fn (array $c): bool => $c['emoji'] === '');

        return count($m->conditions) === 60 && $blank === [];
    });
});

it('renders the icon, text, and provider code for each condition', function () {
    $this->artisan('digests:conditions')->assertSuccessful();

    Mail::assertSent(ConditionsPreviewMail::class, function (ConditionsPreviewMail $m) {
        $html = $m->render();

        // A sampling across the families, each with its mapped icon.
        return str_contains($html, '☀️') && str_contains($html, 'Sunny')
            && str_contains($html, '⛈️') && str_contains($html, 'Moderate or heavy rain with thunder')
            && str_contains($html, '🌫️') && str_contains($html, 'Sandstorm')
            && str_contains($html, '1282'); // a provider code is shown
    });
});
