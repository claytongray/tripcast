<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ $placeShort }} — {{ $positionLine }}</title>
    <style>
        :root { color-scheme: light dark; supported-color-schemes: light dark; }
        @media (prefers-color-scheme: dark) {
            .tc-body { background: #0E1822 !important; }
            .tc-card { background: #16232F !important; }
            .tc-ink { color: #E8EEF4 !important; }
            .tc-ink-secondary { color: #9FB0BF !important; }
            .tc-divider { border-color: #24313D !important; }
        }
    </style>
</head>
<body class="tc-body" style="margin:0; padding:0; background:#F6F9FC;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F9FC;" class="tc-body">
        <tr>
            <td align="center" style="padding:24px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%;">
                    <tr>
                        <td class="tc-card" style="background:#FFFFFF; border-radius:14px; padding:32px;">

                            {{-- Header: place name (display) + countdown/position line --}}
                            <h1 class="tc-ink" style="margin:0 0 4px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:26px; line-height:32px; font-weight:600; color:#16202B;">
                                {{ $placeShort }}
                            </h1>
                            <p class="tc-ink-secondary" style="margin:0 0 24px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:18px; line-height:26px; color:#51616E;">
                                {{ $positionLine }}
                            </p>

                            {{-- Day-over-day narration line (Story 4.2, AD-17/UX-DR5) — calm, omitted when absent --}}
                            @if ($narration)
                                <p class="tc-ink-secondary" style="margin:0 0 24px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:15px; line-height:24px; color:#51616E;">
                                    {{ $narration }}
                                </p>
                            @endif

                            {{-- Trip-window forecast: simple stacked day-rows, single column, hairline divider --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                @foreach ($days as $day)
                                    <tr>
                                        <td class="tc-divider" style="padding:16px 0; border-top:1px solid #E3EAF1; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                                            @if ($day['isDeparture'])
                                                <p class="tc-ink" style="margin:0 0 8px; font-size:14px; line-height:20px; font-weight:600; color:#16202B;">✈️ The start of your trip!</p>
                                            @endif
                                            <p class="tc-ink-secondary" style="margin:0 0 4px; font-size:14px; line-height:20px; color:#51616E;">{{ $day['label'] }}</p>
                                            @if ($day['limited'])
                                                <p class="tc-ink-secondary" style="margin:0; font-size:16px; line-height:24px; color:#51616E;">Limited data</p>
                                            @else
                                                <p class="tc-ink" style="margin:0 0 4px; font-size:17px; line-height:24px; color:#16202B;">{{ $day['emoji'] }} {{ $day['conditionText'] }}</p>
                                                <p class="tc-ink-secondary" style="margin:0; font-size:14px; line-height:20px; color:#51616E; font-variant-numeric:tabular-nums;">{{ $day['high'] }}° / {{ $day['low'] }}° · {{ $day['precipChance'] }}% precip</p>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </table>

                            @if ($limited)
                                <p class="tc-ink-secondary" style="margin:20px 0 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#51616E;">
                                    {{ $limitedLine }}
                                </p>
                            @endif

                            {{-- Affiliate promo slot (Epic 5, AD-18/UX-DR12) — one native unit below the forecast, with mandatory disclosure; absent when null --}}
                            @if ($promo)
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0 0; border-top:1px solid #E3EAF1;">
                                    <tr>
                                        <td style="padding:16px 0 0;" valign="top" width="64">
                                            <a href="{{ $promo->url }}" style="text-decoration:none;">
                                                <img src="{{ $promo->imageUrl }}" alt="{{ $promo->label }}" width="56" height="56" style="display:block; border:0; border-radius:10px;">
                                            </a>
                                        </td>
                                        <td style="padding:16px 0 0 12px;" valign="top">
                                            <a href="{{ $promo->url }}" class="tc-ink" style="font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:15px; line-height:22px; color:#16202B; text-decoration:none;">
                                                {{ $promo->label }}
                                            </a>
                                            <p class="tc-ink-secondary" style="margin:4px 0 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12px; line-height:18px; color:#51616E;">
                                                As an Amazon Associate, tripcast earns from qualifying purchases
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            {{-- Footer. The Feedback line (👍/👎) is Story 2.6 — seam, not built here.
                                 End-trip + Unsubscribe are signed, confirm-then-POST links (FR-5). --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td class="tc-divider" style="padding-top:16px; border-top:1px solid #E3EAF1;">
                                        {{-- Feedback chips (FR-8): one-tap, text labels mandatory (not emoji-only),
                                             ≥44px tap targets, legible with images blocked. --}}
                                        <p style="margin:0 0 16px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                                            <a href="{{ $helpedUrl }}" class="tc-ink" style="display:inline-block; padding:12px 18px; margin:0 6px 6px 0; min-height:20px; border:1px solid #E3EAF1; border-radius:10px; font-size:15px; line-height:20px; color:#16202B; text-decoration:none;">👍 This helped</a>
                                            <a href="{{ $notHelpfulUrl }}" class="tc-ink" style="display:inline-block; padding:12px 18px; margin:0 6px 6px 0; min-height:20px; border:1px solid #E3EAF1; border-radius:10px; font-size:15px; line-height:20px; color:#16202B; text-decoration:none;">👎 Not helpful</a>
                                        </p>
                                        <p class="tc-ink-secondary" style="margin:0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; line-height:22px; color:#51616E;">
                                            <a href="{{ $endTripUrl }}" class="tc-ink-secondary" style="color:#51616E; text-decoration:underline;">End this trip</a>
                                            &nbsp;·&nbsp;
                                            <a href="{{ $unsubscribeUrl }}" class="tc-ink-secondary" style="color:#51616E; text-decoration:underline;">Unsubscribe</a>
                                        </p>
                                        @if ($postalAddress)
                                            <p class="tc-ink-secondary" style="margin:8px 0 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; line-height:20px; color:#51616E;">
                                                {{ $postalAddress }}
                                            </p>
                                        @endif
                                    </td>
                                </tr>
                            </table>

                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
