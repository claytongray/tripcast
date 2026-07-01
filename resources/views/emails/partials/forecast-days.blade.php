                                @foreach ($days as $day)
                                    <tr>
                                        <td class="tc-divider" style="padding:16px 0; border-top:1px solid #E3EAF1; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                                            @if ($day['isDeparture'])
                                                <p class="tc-ink" style="margin:0 0 8px; font-size:14px; line-height:20px; font-weight:600; color:#16202B;">The start of your trip!</p>
                                            @endif
                                            <p class="tc-ink-secondary" style="margin:0 0 4px; font-size:14px; line-height:20px; color:#51616E;">{{ $day['label'] }}</p>
                                            @if ($day['limited'])
                                                <p class="tc-ink-secondary" style="margin:0; font-size:16px; line-height:24px; color:#51616E;">Limited data</p>
                                            @else
                                                <p class="tc-ink" style="margin:0 0 4px; font-size:17px; line-height:24px; color:#16202B; font-variant-numeric:tabular-nums;">{{ $day['emoji'] }} {{ $day['high'] }}° / {{ $day['low'] }}°{{ $day['feelsLike'] !== null ? ' • feels like '.$day['feelsLike'].'°' : '' }}</p>
                                                <p class="tc-ink-secondary" style="margin:0; font-size:14px; line-height:20px; color:#51616E;">{{ $day['conditionText'] }} • {{ $day['precipChance'] }}% precipitation{{ $day['humidity'] !== null ? ' • '.$day['humidity'].'% humidity' : '' }}</p>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
