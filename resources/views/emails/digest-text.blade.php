{{ $placeShort }}
{{ $positionLine }}
@if ($narration)

{!! $narration !!}
@endif

@foreach ($days as $day)
@if ($day['isDeparture'])
✈️ The start of your trip!
@endif
@if ($day['limited'])
{{ $day['label'] }} — Limited data
@else
{{ $day['label'] }} — {{ $day['emoji'] }} {{ $day['conditionText'] }} · {{ $day['high'] }}° / {{ $day['low'] }}° · {{ $day['precipChance'] }}% precip
@endif
@endforeach
@if ($limited)

{{ $limitedLine }}
@endif
@if ($promo)

Sponsored
{{ $promo->label }}
{{ $promoUrl }}
As an Amazon Associate, tripcast earns from qualifying purchases
@endif

This helped: {{ $helpedUrl }}
Not helpful: {{ $notHelpfulUrl }}

End this trip: {{ $endTripUrl }}
Unsubscribe: {{ $unsubscribeUrl }}
@if ($postalAddress)

{{ $postalAddress }}
@endif
