<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ $canonicalPlaceName }} — {{ $headerLine }}</title>
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

                            <p class="tc-ink-secondary" style="margin:0 0 12px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12px; line-height:16px; letter-spacing:0.06em; text-transform:uppercase; color:#51616E;">Sample tripcast</p>

                            <h1 class="tc-ink" style="margin:0 0 6px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:26px; line-height:32px; font-weight:600; color:#16202B;">
                                {{ $placeShort }}
                            </h1>
                            <p class="tc-ink" style="margin:0 0 2px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:19px; line-height:26px; font-weight:600; color:#16202B;">
                                {{ $headerLine }}
                            </p>
                            <p class="tc-ink-secondary" style="margin:0 0 28px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; line-height:20px; color:#51616E;">
                                {{ $dateRange }}
                            </p>

                            {{-- Trip-window forecast: shared day-row partial (identical to the digest) --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                @include('emails.partials.forecast-days')
                            </table>

                            {{-- Sample CTA: the link doubles as the account verify/login (magic link) --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:32px;">
                                <tr>
                                    <td class="tc-divider" style="border-top:1px solid #E3EAF1; padding-top:28px;" align="center">
                                        <p class="tc-ink" style="margin:0 0 16px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#16202B;">Ready to create your own?</p>
                                        <a href="{{ $getStartedUrl }}" style="display:inline-block; background:#2563A6; color:#FFFFFF; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:20px; font-weight:600; text-decoration:none; padding:12px 24px; border-radius:8px;">Get started &rarr;</a>
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
