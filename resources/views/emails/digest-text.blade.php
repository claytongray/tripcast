@if ($welcome)
You're all set for {{ $placeShort }}

Your tripcast for {{ $place }} is set — {{ $dateRange }}. Here's your first tripcast:

@endif
{{ $placeShort }}
{{ $headerLine }}
{{ $dateRange }}
@if ($narration)

Overview
{!! $narration !!}
@endif

@include('emails.partials.forecast-days-text')
@if ($futureRange)
{{ $futureRange }} — {{ $futureNote }}
@endif
@if ($limited)

{{ $limitedLine }}
@endif
@if ($promo)

Sponsored
{{ $promo->label }}
{{ $promoCta }}: {{ $promoUrl }}
As an Amazon Associate, tripcast earns from qualifying purchases
@endif

This helped: {{ $helpedUrl }}
Not helpful: {{ $notHelpfulUrl }}

End this trip: {{ $endTripUrl }}
Unsubscribe: {{ $unsubscribeUrl }}

@include('emails.partials.legal-footer-text')
