<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>Weather condition icons</title>
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

                            <h1 class="tc-ink" style="margin:0 0 6px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:24px; line-height:30px; font-weight:600; color:#16202B;">
                                Weather condition icons
                            </h1>
                            <p class="tc-ink-secondary" style="margin:0 0 24px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; line-height:20px; color:#51616E;">
                                Every WeatherAPI condition and the icon the digest shows for it ({{ count($conditions) }} total).
                            </p>

                            {{-- One row per condition: icon · condition text · provider code. --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                @foreach ($conditions as $condition)
                                    <tr>
                                        <td class="tc-divider" valign="middle" width="36" style="padding:12px 0; border-top:1px solid #E3EAF1; font-size:20px; line-height:24px;">
                                            {{ $condition['emoji'] !== '' ? $condition['emoji'] : '—' }}
                                        </td>
                                        <td class="tc-divider" valign="middle" style="padding:12px 0; border-top:1px solid #E3EAF1; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                                            <span class="tc-ink" style="font-size:16px; line-height:22px; color:#16202B;">{{ $condition['text'] }}</span>
                                        </td>
                                        <td class="tc-divider" valign="middle" align="right" style="padding:12px 0; border-top:1px solid #E3EAF1; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                                            <span class="tc-ink-secondary" style="font-size:13px; line-height:20px; color:#9FB0BF; font-variant-numeric:tabular-nums;">{{ $condition['code'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </table>

                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
