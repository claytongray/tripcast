<?php

use App\Mail\DigestMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

// Apostrophe-free, unique to the forecast-level "more info soon" line (the HTML
// escapes "we'll", so match the tail instead).
const LIMITED_LINE = 'full picture tomorrow';

beforeEach(function () {
    // 2026-06-30 is a Tuesday → the upcoming Saturday (07-04) is within the
    // 7-day window, so the three scenarios render deterministically.
    Carbon::setTestNow(Carbon::parse('2026-06-30 09:00', 'America/New_York'));
    Mail::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('sends three sample digests to the given mailbox', function () {
    $this->artisan('digests:preview', ['--email' => 'me@example.test'])->assertSuccessful();

    Mail::assertSent(DigestMail::class, 3);
    Mail::assertSent(DigestMail::class, fn (DigestMail $m) => $m->hasTo('me@example.test'));
});

it('renders the full forecast for a trip inside the window (no more-info line)', function () {
    $this->artisan('digests:preview')->assertSuccessful();

    Mail::assertSent(DigestMail::class, function (DigestMail $m) {
        if ($m->trip->canonical_place_name !== 'Ocean City, NJ') {
            return false;
        }

        $html = $m->render();

        // Both weekend days render; no "more info soon" line.
        return ! str_contains($html, LIMITED_LINE) && substr_count($html, '% precip') === 2;
    });
});

it('shows a partial forecast + the more-info line for a trip partly beyond the window', function () {
    $this->artisan('digests:preview')->assertSuccessful();

    Mail::assertSent(DigestMail::class, function (DigestMail $m) {
        if ($m->trip->canonical_place_name !== 'Columbus, OH') {
            return false;
        }

        $html = $m->render();

        // Window 07-03..07-09; the 7-day forecast (06-30..07-06) covers 07-03..07-06 → 4 days.
        return str_contains($html, LIMITED_LINE) && substr_count($html, '% precip') === 4;
    });
});

it('shows only the first day for a trip six days out', function () {
    $this->artisan('digests:preview')->assertSuccessful();

    Mail::assertSent(DigestMail::class, function (DigestMail $m) {
        if ($m->trip->canonical_place_name !== 'Asheville, NC') {
            return false;
        }

        $html = $m->render();

        // Departs 07-06 (the window's last day) → exactly one day renders + more-info.
        return str_contains($html, LIMITED_LINE) && substr_count($html, '% precip') === 1;
    });
});
