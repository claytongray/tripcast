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
use Illuminate\Support\Facades\Mail;

/**
 * The public "send me a sample" endpoint. Issues a magic link (the email's
 * "Get started" CTA), queues the sample digest for the fixed demo destination,
 * and records the request for acquisition tracking.
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
     * An unsaved demo trip (no DB writes) for the fixed destination, windowed
     * tomorrow..tomorrow+1 so the configured forecast horizon (8+ days) fully
     * covers it. The user relation drives the render's temperature unit.
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
            'return_date' => $today->addDays(2)->toDateString(),
            'status' => Trip::STATUS_ACTIVE,
        ]);
        $trip->setRelation('user', $user);

        return $trip;
    }
}
