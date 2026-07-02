<?php

use App\Models\EmailLog;
use App\Models\Trip;
use App\Models\User;
use App\Services\Metrics\MetricsService;
use Illuminate\Support\Facades\DB;

// Pin "now" so window bounds and seeded dates are deterministic. travelTo()
// auto-restores in tearDown.
beforeEach(function () {
    $this->travelTo('2026-07-01 12:00:00');
    $this->service = new MetricsService;
});

describe('resolveWindow', function () {
    it('resolves the 7-day window to inclusive bounds and a prior period', function () {
        $window = $this->service->resolveWindow(7);

        expect($window->days)->toBe(7)
            ->and($window->start->toDateString())->toBe('2026-06-25')
            ->and($window->end->toDateString())->toBe('2026-07-01')
            ->and($window->previousEnd->toDateString())->toBe('2026-06-24')
            ->and($window->previousStart->toDateString())->toBe('2026-06-18')
            ->and($window->dates())->toHaveCount(7)
            ->and($window->dates())->toBe([
                '2026-06-25', '2026-06-26', '2026-06-27', '2026-06-28',
                '2026-06-29', '2026-06-30', '2026-07-01',
            ]);
    });

    it('supports 30- and 90-day windows', function (int $days) {
        expect($this->service->resolveWindow($days)->dates())->toHaveCount($days);
    })->with([30, 90]);

    it('rejects windows outside the allowlist', function (int $days) {
        expect(fn () => $this->service->resolveWindow($days))
            ->toThrow(InvalidArgumentException::class);
    })->with([45, 1, 0, -7]);
});

describe('dailyCountsByTimestamp', function () {
    it('buckets a timestamp column by day, zero-filling gaps and excluding out-of-window rows', function () {
        User::factory()->create(['created_at' => '2026-06-25 09:00:00']);
        User::factory()->count(2)->create(['created_at' => '2026-06-27 09:00:00']);
        User::factory()->create(['created_at' => '2026-07-01 23:30:00']);
        // Outside the 7-day window (falls in the previous period) — must be excluded.
        User::factory()->create(['created_at' => '2026-06-20 09:00:00']);

        $window = $this->service->resolveWindow(7);
        $series = $this->service->dailyCountsByTimestamp(User::query(), 'created_at', $window);

        expect($series)->toBe([
            ['date' => '2026-06-25', 'count' => 1],
            ['date' => '2026-06-26', 'count' => 0],
            ['date' => '2026-06-27', 'count' => 2],
            ['date' => '2026-06-28', 'count' => 0],
            ['date' => '2026-06-29', 'count' => 0],
            ['date' => '2026-06-30', 'count' => 0],
            ['date' => '2026-07-01', 'count' => 1],
        ]);
    });

    it('issues exactly one grouped query for the series (no N+1)', function () {
        User::factory()->create(['created_at' => '2026-06-26 09:00:00']);
        $window = $this->service->resolveWindow(7);

        DB::enableQueryLog();
        $this->service->dailyCountsByTimestamp(User::query(), 'created_at', $window);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect($queries)->toHaveCount(1);
    });
});

describe('dailyCountsByDate', function () {
    it('buckets a date column by day, zero-filling gaps and excluding out-of-window rows', function () {
        $tripA = Trip::factory()->for(User::factory())->create();
        $tripB = Trip::factory()->for(User::factory())->create();

        $tripA->emailLogs()->create(['send_date' => '2026-06-26', 'status' => EmailLog::STATUS_SENT]);
        $tripB->emailLogs()->create(['send_date' => '2026-06-26', 'status' => EmailLog::STATUS_SENT]);
        $tripA->emailLogs()->create(['send_date' => '2026-07-01', 'status' => EmailLog::STATUS_FAILED]);
        // Out of window.
        $tripA->emailLogs()->create(['send_date' => '2026-06-10', 'status' => EmailLog::STATUS_SENT]);

        $window = $this->service->resolveWindow(7);
        $series = $this->service->dailyCountsByDate(EmailLog::query(), 'send_date', $window);

        expect($series)->toHaveCount(7)
            ->and(collect($series)->firstWhere('date', '2026-06-26')['count'])->toBe(2)
            ->and(collect($series)->firstWhere('date', '2026-07-01')['count'])->toBe(1)
            ->and(collect($series)->firstWhere('date', '2026-06-28')['count'])->toBe(0);
    });
});

describe('zero-fill on empty ranges', function () {
    it('returns all-zero buckets when no rows exist', function () {
        $window = $this->service->resolveWindow(7);
        $series = $this->service->dailyCountsByTimestamp(User::query(), 'created_at', $window);

        expect($series)->toHaveCount(7)
            ->and(collect($series)->pluck('count')->sum())->toBe(0)
            ->and(collect($series)->pluck('count')->every(fn ($c) => $c === 0))->toBeTrue();
    });
});

describe('tile', function () {
    it('computes delta and percentage change', function () {
        expect($this->service->tile(10, 8))->toBe([
            'value' => 10, 'previous' => 8, 'delta' => 2, 'delta_pct' => 25.0,
        ]);
    });

    it('returns a null percentage when there is no prior baseline', function () {
        expect($this->service->tile(5, 0))->toBe([
            'value' => 5, 'previous' => 0, 'delta' => 5, 'delta_pct' => null,
        ]);
    });

    it('handles a decline to zero', function () {
        expect($this->service->tile(0, 4))->toBe([
            'value' => 0, 'previous' => 4, 'delta' => -4, 'delta_pct' => -100.0,
        ]);
    });
});
