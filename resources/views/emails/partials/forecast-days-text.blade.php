@foreach ($days as $day)
@if ($day['isDeparture'])
The start of your trip!
@endif
@if ($day['limited'])
{{ $day['label'] }} — Limited data
@else
{{ $day['label'] }} — {{ $day['emoji'] }} {{ $day['high'] }}° / {{ $day['low'] }}°{{ $day['feelsLike'] !== null ? ' • feels like '.$day['feelsLike'].'°' : '' }} • {{ $day['conditionText'] }} • {{ $day['precipChance'] }}% precipitation{{ $day['humidity'] !== null ? ' • '.$day['humidity'].'% humidity' : '' }}
@endif
@endforeach
