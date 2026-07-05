<?php

namespace App\Services\Digest;

use App\Mail\DigestMail;
use App\Models\EmailLog;
use App\Models\Trip;
use App\Models\User;
use App\Services\Narration\ClaudeNarrator;
use App\Services\Narration\NarrationContext;
use App\Services\Narration\Narrator;
use App\Services\Promo\Promo;
use App\Services\Promo\PromoProvider;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single authority for assembling a digest (AD-17/AD-18/AD-19): the
 * day-over-day narration line and the entitlement-gated promo slot, folded into
 * a ready-to-send DigestMail. Shared by the scheduled SendTripDigest job and the
 * admin-triggered AdminDigestSender so the narration/promo seam has one home and
 * never drifts. All internals are guarded — any failure yields a null line / null
 * slot, never a thrown or delayed send.
 */
class DigestComposer
{
    public function __construct(
        private readonly Narrator $narrator,
        private readonly PromoProvider $promoProvider,
    ) {}

    /**
     * @param  array{days: list<array<string, mixed>>, limited: bool}  $snapshot
     */
    public function compose(Trip $trip, array $snapshot, string $sendDate, bool $welcome = false): ComposedDigest
    {
        $narration = $this->narrate($trip, $snapshot, $sendDate);
        $promo = $this->selectPromo($trip, $snapshot, $sendDate);

        return new ComposedDigest(
            new DigestMail($trip, $snapshot, $sendDate, $narration, $promo, $welcome),
            $promo,
        );
    }

    /**
     * Select the one weather-keyed promo (AD-18), gated on entitlement (AD-19)
     * and guarded: only free-tier users see a promo, and any selection failure
     * yields no slot.
     *
     * @param  array{days: list<array<string, mixed>>, limited: bool}  $snapshot
     */
    private function selectPromo(Trip $trip, array $snapshot, string $sendDate): ?Promo
    {
        if (! $trip->user->shouldShowPromo()) {
            return null;
        }

        try {
            return $this->promoProvider->select($snapshot, $sendDate);
        } catch (Throwable $e) {
            Log::warning('promo selection failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Build the calm day-over-day line (AD-17). Reads the prior send's snapshot
     * for this trip (AD-9, read-only), runs the live deterministic narrator, and
     * — when shadow is enabled — logs the Claude line alongside for comparison.
     * Strictly off the delivery path: any error yields a null line.
     *
     * @param  array{days: list<array<string, mixed>>, limited: bool}  $snapshot
     */
    private function narrate(Trip $trip, array $snapshot, string $sendDate): ?string
    {
        $prior = EmailLog::query()
            ->where('trip_id', $trip->id)
            ->where('send_date', '<', $sendDate)
            ->whereNotNull('weather_snapshot')
            ->orderByDesc('send_date')
            ->first()?->weather_snapshot;

        $context = new NarrationContext(
            priorSnapshot: $prior,
            currentSnapshot: $snapshot,
            celsius: $trip->user->temperature_unit === User::UNIT_CELSIUS,
            departureDate: $trip->departure_date->toDateString(),
            returnDate: $trip->return_date->toDateString(),
        );

        $line = $this->narrateSafely($this->narrator, $context);

        if (config('tripcast.narration.shadow')) {
            $shadow = $this->narrateSafely(app(ClaudeNarrator::class), $context);

            Log::info('narrator:compare', [
                'trip_id' => $trip->id,
                'send_date' => $sendDate,
                'deterministic' => $line,
                'claude' => $shadow,
            ]);
        }

        return $line;
    }

    /**
     * Run a narrator, swallowing any failure (AD-17: never break/delay the send).
     */
    private function narrateSafely(Narrator $narrator, NarrationContext $context): ?string
    {
        try {
            return $narrator->narrate($context);
        } catch (Throwable $e) {
            Log::warning('narration failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
