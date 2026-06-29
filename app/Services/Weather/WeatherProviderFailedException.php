<?php

namespace App\Services\Weather;

use RuntimeException;

/**
 * Thrown by a WeatherProvider adapter when a forecast can't be fetched (transport
 * error, non-OK response, unparseable body). Caught at the send-Job boundary
 * (Epic 2) → log and send nothing, never a broken digest (AD-1, AD-4).
 */
class WeatherProviderFailedException extends RuntimeException {}
