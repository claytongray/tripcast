<?php

use App\Models\EmailLog;
use App\Models\Trip;
use App\Models\User;

/**
 * Guards against the class of bug the Epic 7 review found: two admin sections
 * computing the "same" metric with different formulas. Send success rate =
 * sent / (sent + failed) — in-progress `sending` rows are excluded — and the
 * Overview and Emails sections must agree.
 */
beforeEach(function () {
    $this->travelTo('2026-07-01 12:00:00'); // window end == "today"
    $this->admin = User::factory()->admin()->confirmed()->create();
});

it('computes send success rate identically on Overview and Emails', function () {
    // All sends dated today (= window end), so Overview's "today" set and the
    // Emails window cover the same rows: 3 sent, 1 failed, 2 in-progress.
    $log = fn (string $status) => Trip::factory()->for(User::factory())->create()
        ->emailLogs()->create(['send_date' => '2026-07-01', 'status' => $status, 'claimed_at' => now()]);

    $log(EmailLog::STATUS_SENT);
    $log(EmailLog::STATUS_SENT);
    $log(EmailLog::STATUS_SENT);
    $log(EmailLog::STATUS_FAILED);
    $log(EmailLog::STATUS_SENDING);
    $log(EmailLog::STATUS_SENDING);

    $overviewRate = $this->actingAs($this->admin)->get(route('admin.overview'))
        ->viewData('page')['props']['kpis']['sends_today']['success_rate'];

    $emailsRate = $this->actingAs($this->admin)->get(route('admin.emails'))
        ->viewData('page')['props']['totals']['success_rate'];

    // 3 / (3 + 1) = 75.0 on both — sending rows excluded.
    expect((float) $overviewRate)->toBe(75.0)
        ->and((float) $emailsRate)->toBe(75.0)
        ->and((float) $overviewRate)->toBe((float) $emailsRate);
});
