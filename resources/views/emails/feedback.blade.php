<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Feedback from {{ $email }}</title>
</head>
{{-- Internal ops notification (Story 10.1) — plain and scannable, no branding. --}}
<body style="margin:0; padding:24px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:26px; color:#16202B;">
    <p style="margin:0 0 16px; white-space:pre-wrap;">{{ $userMessage }}</p>

    <hr style="border:none; border-top:1px solid #E3EAF1; margin:0 0 16px;">

    <p style="margin:0; font-size:13px; line-height:20px; color:#51616E;">
        From: {{ $email }} (reply to respond directly)<br>
        Source: {{ $source }}<br>
        Trips: {{ $tripCount }}
    </p>
</body>
</html>
