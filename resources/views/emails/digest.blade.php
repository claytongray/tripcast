<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ $placeShort }} — {{ $headerLine }}</title>
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
                        <td class="tc-card" style="background:#FFFFFF; border-radius:14px; padding:32px 32px 36px;">

                            {{-- Header: place (heading) → countdown ("5 days to go!") → trip dates.
                                 The place is the heading, so the sub-lines never repeat it. --}}
                            <h1 class="tc-ink" style="margin:0 0 6px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:26px; line-height:32px; font-weight:600; color:#16202B;">
                                {{ $placeShort }}
                            </h1>
                            <p class="tc-ink" style="margin:0 0 2px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:19px; line-height:26px; font-weight:600; color:#16202B;">
                                {{ $headerLine }}
                            </p>
                            <p class="tc-ink-secondary" style="margin:0 0 28px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; line-height:20px; color:#51616E;">
                                {{ $dateRange }}
                            </p>

                            {{-- Day-over-day narration (Story 4.2, AD-17/UX-DR5) — titled "Overview", calm, omitted when absent --}}
                            @if ($narration)
                                <p class="tc-ink" style="margin:0 0 4px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; line-height:20px; font-weight:600; color:#16202B;">Overview</p>
                                <p class="tc-ink-secondary" style="margin:0 0 24px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:15px; line-height:24px; color:#51616E;">
                                    {{ $narration }}
                                </p>
                            @endif

                            {{-- Trip-window forecast: simple stacked day-rows, single column, hairline divider --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                @include('emails.partials.forecast-days')

                                {{-- Itinerary days still beyond the forecast horizon (FR-7):
                                     the full trip stays visible, but their forecast arrives
                                     later — one calm collapsed row, never fabricated values. --}}
                                @if ($futureRange)
                                    <tr>
                                        <td class="tc-divider" style="padding:16px 0; border-top:1px solid #E3EAF1; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                                            <p class="tc-ink-secondary" style="margin:0 0 4px; font-size:14px; line-height:20px; color:#51616E;">{{ $futureRange }}</p>
                                            <p class="tc-ink-secondary" style="margin:0; font-size:15px; line-height:22px; color:#51616E;">{{ $futureNote }}</p>
                                        </td>
                                    </tr>
                                @endif
                            </table>

                            @if ($limited)
                                <p class="tc-ink-secondary" style="margin:20px 0 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#51616E;">
                                    {{ $limitedLine }}
                                </p>
                            @endif

                            {{-- Affiliate promo slot (Epic 5, AD-18/UX-DR12) — one quiet text unit
                                 below the forecast: "Sponsored" kicker, label link, optional
                                 description, disclosure. No image, no CTA (2026-07-03 spec):
                                 editorial, not banner. --}}
                            @if ($promo)
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:36px 0 0; border-top:1px solid #E3EAF1;">
                                    <tr>
                                        <td style="padding:20px 0 28px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                                            <p class="tc-ink-secondary" style="margin:0 0 6px; font-size:11px; line-height:16px; letter-spacing:0.08em; text-transform:uppercase; color:#9FB0BF;">Sponsored</p>
                                            <a href="{{ $promoUrl }}" class="tc-ink" style="display:inline-block; font-size:15px; line-height:20px; font-weight:600; color:#2563A6; text-decoration:none;">{{ $promo->label }}</a>
                                            @if ($promo->description)
                                                <p class="tc-ink-secondary tc-promo-desc" style="margin:4px 0 0; font-size:14px; line-height:20px; color:#51616E;">{{ $promo->description }}</p>
                                            @endif
                                            <p class="tc-ink-secondary" style="margin:12px 0 0; font-size:12px; line-height:18px; color:#9FB0BF;">As an Amazon Associate, tripcast earns from qualifying purchases</p>
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            {{-- Footer. The Feedback line (👍/👎) is Story 2.6 — seam, not built here.
                                 End-trip + Unsubscribe are signed, confirm-then-POST links (FR-5). --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td class="tc-divider" style="padding-top:28px; border-top:1px solid #E3EAF1;">
                                        {{-- Feedback chips (FR-8): one-tap, text labels mandatory (not emoji-only),
                                             ≥44px tap targets, legible with images blocked. A 2-cell table keeps
                                             them side-by-side and compact even on a narrow phone. --}}
                                        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                                            <tr>
                                                <td valign="middle" style="padding:0 8px 0 0;">
                                                    <a href="{{ $helpedUrl }}" class="tc-ink" style="display:inline-block; padding:12px 15px; min-height:20px; border:1px solid #E3EAF1; border-radius:10px; font-size:14px; line-height:20px; white-space:nowrap; color:#16202B; text-decoration:none;">👍 This helped</a>
                                                </td>
                                                <td valign="middle" style="padding:0;">
                                                    <a href="{{ $notHelpfulUrl }}" class="tc-ink" style="display:inline-block; padding:12px 15px; min-height:20px; border:1px solid #E3EAF1; border-radius:10px; font-size:14px; line-height:20px; white-space:nowrap; color:#16202B; text-decoration:none;">👎 Not helpful</a>
                                                </td>
                                            </tr>
                                        </table>
                                        {{-- Free-text feedback nudge (Story 10.1): replies to the digest land in
                                             the team inbox (sent from the hello@ address). --}}
                                        <p class="tc-ink-secondary" style="margin:0 0 20px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; line-height:22px; color:#51616E;">
                                            How's tripcast working? Have an idea? Simply reply to this email and tell us.
                                        </p>
                                        <p class="tc-ink-secondary" style="margin:0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; line-height:22px; color:#51616E;">
                                            <a href="{{ $endTripUrl }}" class="tc-ink-secondary" style="color:#51616E; text-decoration:underline;">End this trip</a>
                                            &nbsp;·&nbsp;
                                            <a href="{{ $unsubscribeUrl }}" class="tc-ink-secondary" style="color:#51616E; text-decoration:underline;">Unsubscribe</a>
                                        </p>
                                        @include('emails.partials.legal-footer')
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
