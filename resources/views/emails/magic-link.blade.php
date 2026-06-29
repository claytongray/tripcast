<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>Your tripcast sign-in link</title>
    <style>
        :root { color-scheme: light dark; supported-color-schemes: light dark; }
        @media (prefers-color-scheme: dark) {
            .tc-body { background: #0E1822 !important; }
            .tc-card { background: #16232F !important; }
            .tc-ink { color: #E8EEF4 !important; }
            .tc-ink-secondary { color: #9FB0BF !important; }
            .tc-hairline { border-color: #243340 !important; }
        }
    </style>
</head>
<body class="tc-body" style="margin:0; padding:0; background:#F6F9FC;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F9FC;" class="tc-body">
        <tr>
            <td align="center" style="padding:24px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%;">
                    <tr>
                        <td class="tc-card" style="background:#FFFFFF; border:1px solid #E3EAF1; border-radius:14px; padding:32px;">
                            <p class="tc-ink" style="margin:0 0 8px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:22px; line-height:30px; font-weight:600; color:#16202B;">
                                Sign in to tripcast
                            </p>
                            <p class="tc-ink-secondary" style="margin:0 0 24px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:26px; color:#51616E;">
                                Tap the button below to sign in. This link expires in {{ $ttlMinutes }} minutes and can be used once.
                            </p>

                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="border-radius:8px; background:#2563A6;">
                                        <a href="{{ $url }}" style="display:inline-block; padding:12px 24px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:26px; font-weight:600; color:#FFFFFF; text-decoration:none; border-radius:8px;">
                                            Sign in
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p class="tc-ink-secondary" style="margin:24px 0 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; line-height:20px; color:#51616E;">
                                If you didn't request this, you can ignore this email — nothing will happen.
                            </p>
                            <p class="tc-ink-secondary" style="margin:16px 0 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; line-height:20px; color:#51616E; word-break:break-all;">
                                Or paste this link into your browser:<br>{{ $url }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
