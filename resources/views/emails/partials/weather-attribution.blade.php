{{-- Apple Weather attribution (Story 11.3 / CAP-8). WeatherKit's license REQUIRES
     the Apple Weather trademark + a link to Apple's legal data-source page wherever
     its data is shown — so this is gated on the active provider flag, never a
     feature toggle: the attribution can never be on with the data absent (or
     vice-versa).

     Rendered as TEXT, not the Apple logo image: Gmail/Outlook strip inlined
     data-URI images, so an image mark silently vanishes in most inboxes. Text
     satisfies the mandate ("clearly display the Apple Weather trademark") and the
     colour adapts to the dark card automatically via the .tc-ink-secondary class
     defined in the digest head. Wording matches the plain-text twin. --}}
@if (config('tripcast.forecast.provider') === 'weatherkit')
    <p class="tc-ink-secondary" style="margin:16px 0 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; line-height:20px; color:#51616E;">
        Weather data by <a href="https://developer.apple.com/weatherkit/data-source-attribution/" class="tc-ink-secondary" style="color:#51616E; text-decoration:underline;">Apple Weather</a>
    </p>
@endif
