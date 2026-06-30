<?php

namespace App\Services\Narration;

/**
 * The grounded inputs for narration (AD-17): the prior and current persisted
 * snapshots (the `{days, limited}` shape), the owner's unit, and the trip
 * window. Everything a narrator may use — it never reaches past this.
 */
final class NarrationContext
{
    /**
     * @param  array<string, mixed>|null  $priorSnapshot
     * @param  array<string, mixed>  $currentSnapshot
     */
    public function __construct(
        public readonly ?array $priorSnapshot,
        public readonly array $currentSnapshot,
        public readonly bool $celsius,
        public readonly string $departureDate,
        public readonly string $returnDate,
    ) {}
}
