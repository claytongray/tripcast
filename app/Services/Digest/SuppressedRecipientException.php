<?php

namespace App\Services\Digest;

use RuntimeException;

/**
 * Thrown when an admin send-to-owner is refused because the owner is suppressed
 * (unconfirmed, AD-11; or opted out, AD-13). The message is a human-readable
 * reason surfaced to the admin — the admin action is not a backdoor around
 * account-level email suppression.
 */
class SuppressedRecipientException extends RuntimeException {}
