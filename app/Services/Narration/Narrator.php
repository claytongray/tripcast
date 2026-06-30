<?php

namespace App\Services\Narration;

/**
 * The day-over-day narration port (AD-17, exactly like AD-1). Code depends on
 * this interface; the concrete adapter (deterministic templates, or the Claude
 * SDK) is bound in a ServiceProvider. Returns a single calm sentence grounded
 * strictly in the snapshots, or null when there is nothing notable to say.
 */
interface Narrator
{
    public function narrate(NarrationContext $context): ?string;
}
