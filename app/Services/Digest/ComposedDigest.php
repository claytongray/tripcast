<?php

namespace App\Services\Digest;

use App\Mail\DigestMail;
use App\Services\Promo\Promo;

/**
 * The output of {@see DigestComposer::compose()}: a ready-to-send DigestMail
 * plus the promo that was selected for it. The caller decides what to do with
 * the promo — the scheduled job records an impression on the sent path; the
 * admin sender deliberately never does (out-of-band, no analytics distortion).
 */
final class ComposedDigest
{
    public function __construct(
        public readonly DigestMail $mail,
        public readonly ?Promo $promo,
    ) {}
}
