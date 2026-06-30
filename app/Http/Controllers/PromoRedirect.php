<?php

namespace App\Http\Controllers;

use App\Models\PromoEvent;
use App\Models\Trip;
use App\Services\Promo\PromoProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Promo-click attribution (FR-18, AD-18): the one signed action that stays a GET.
 * Reads the slug, logs an idempotent `click` promo_event, then forwards to the
 * tagged Amazon URL. It mutates no app state beyond the idempotent append, so a
 * mail-client prefetch is harmless. No raw affiliate link ever sits in the email
 * body — only this signed redirect.
 */
class PromoRedirect extends Controller
{
    public function click(Request $request, Trip $trip, string $slug, PromoProvider $promos): RedirectResponse
    {
        $promo = $promos->findBySlug($slug);

        if ($promo === null) {
            abort(404);
        }

        // The send_date rides as a signed query param (covered by the signature).
        $sendDate = (string) $request->query('send_date', '');

        PromoEvent::record($trip, $sendDate, $slug, PromoEvent::EVENT_CLICK);

        return redirect()->away($promo->url);
    }
}
