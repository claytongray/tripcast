{{-- Legal footer (FR-26): Privacy/Terms links (absolute URLs) + the CAN-SPAM
     postal address. Shared by the digest, welcome, and sample emails; expects
     $postalAddress in scope. Links-only markup — the caller owns the divider. --}}
<p class="tc-ink-secondary" style="margin:8px 0 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; line-height:20px; color:#51616E;">
    <a href="{{ route('privacy') }}" class="tc-ink-secondary" style="color:#51616E; text-decoration:underline;">Privacy</a>
    &nbsp;·&nbsp;
    <a href="{{ route('terms') }}" class="tc-ink-secondary" style="color:#51616E; text-decoration:underline;">Terms</a>
</p>
@if ($postalAddress)
    <p class="tc-ink-secondary" style="margin:8px 0 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; line-height:20px; color:#51616E;">
        {{ $postalAddress }}
    </p>
@endif
