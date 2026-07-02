<?php

namespace App\Http\Controllers;

use App\Actions\RequestMagicLink;
use App\Http\Controllers\Concerns\ThrottlesMagicLink;
use App\Http\Requests\SendSampleRequest;
use App\Mail\SampleDigestMail;
use App\Models\SampleRequest;
use App\Models\Trip;
use App\Models\User;
use App\Services\Sample\SampleForecast;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Sample endpoints: public (store) and authenticated (storeForSelf) routes.
 * Issues a magic link for public requests (the email's "Get started" CTA),
 * queues the sample digest for the fixed demo destination, and records the
 * request for acquisition tracking. The authenticated endpoint sends direct to
 * the user without magic link or acquisition tracking.
 */
class SampleController extends Controller
{
    use ThrottlesMagicLink;

    public function store(SendSampleRequest $request, RequestMagicLink $magicLink, SampleForecast $sampleForecast): RedirectResponse
    {
        $email = $request->validated()['email'];

        $this->ensureNotThrottled($request, $email);

        // Sample CTA is a nurture link opened later, so it lives longer than a login link.
        $issued = $magicLink->issue($email, (int) config('tripcast.sample.magic_link_ttl_minutes'));
        $destination = config('tripcast.sample.destination');

        $trip = $this->sampleTrip($destination, $issued['user']);
        $snapshot = $sampleForecast->forecast()->toArray();

        Mail::to($email)->queue(new SampleDigestMail($trip, $snapshot, $issued['url']));

        SampleRequest::create([
            'user_id' => $issued['user']->id,
            'email' => $email,
            'destination' => $destination['key'],
        ]);

        return back()->with('sample_sent', $email);
    }

    /**
     * The dashboard "send a sample" action: queues the same sample digest to the
     * signed-in user's own address. No magic link (the CTA returns them to the
     * dashboard) and no SampleRequest row (that table tracks acquisition leads).
     * Per-user limiter instead of the magic-link buckets so samples can never
     * consume login-link attempts.
     */
    public function storeForSelf(Request $request, SampleForecast $sampleForecast): RedirectResponse
    {
        $user = $request->user();
        $key = 'sample-self:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages([
                'sample' => "That's a few samples already — try again in about an hour.",
            ]);
        }

        RateLimiter::hit($key, 3600);

        $trip = $this->sampleTrip(config('tripcast.sample.destination'), $user);
        $snapshot = $sampleForecast->forecast()->toArray();

        Mail::to($user->email)->queue(new SampleDigestMail($trip, $snapshot, route('dashboard')));

        return back();
    }

    /**
     * An unsaved demo trip (no DB writes) for the fixed destination, windowed
     * tomorrow..tomorrow+6 so the sample renders a full 7-day forecast (FR-25 —
     * the product at full strength, not a two-day sliver). The live fetch spans
     * today..today+(horizon 7), so every window day has a row; one day wider
     * would silently drop from the render. The user relation drives the
     * render's temperature unit.
     *
     * @param  array{key:string,label:string,latitude:float,longitude:float}  $destination
     */
    private function sampleTrip(array $destination, User $user): Trip
    {
        $today = CarbonImmutable::now('America/New_York');

        $trip = new Trip([
            'destination_raw' => $destination['label'],
            'canonical_place_name' => $destination['label'],
            'latitude' => $destination['latitude'],
            'longitude' => $destination['longitude'],
            'departure_date' => $today->addDay()->toDateString(),
            'return_date' => $today->addDays(7)->toDateString(),
            'status' => Trip::STATUS_ACTIVE,
        ]);
        $trip->setRelation('user', $user);

        return $trip;
    }
}
