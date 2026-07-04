<?php

namespace App\Http\Controllers;

use App\Console\Commands\SendDailyDigests;
use App\Models\AdminEmailSend;
use App\Models\EmailLog;
use App\Models\Trip;
use App\Models\User;
use App\Services\Metrics\EmailHealthMetrics;
use App\Services\Metrics\MetricsService;
use App\Services\Metrics\OverviewMetrics;
use App\Services\Metrics\PromoAnalytics;
use App\Services\Metrics\SampleFunnelMetrics;
use App\Services\Metrics\SendProjection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin observability panel (Epic 7, FR-22). Read-only sections behind the
 * single `admin` Gate (AD-12), registered under one guarded `/admin` group.
 * The Overview/Users/Emails/Promos/Samples sections are shell placeholders here
 * (Story 7.1) — Stories 7.3–7.7 fill each with real metrics. Monitoring (FR-13)
 * is the one section with live content, reading `email_logs` (AD-9).
 */
class AdminController extends Controller
{
    /** Default metrics window (days) when none/invalid is requested. */
    private const DEFAULT_WINDOW = 30;

    /** Page size for the users explorer. */
    private const USERS_PER_PAGE = 25;

    /**
     * Overview dashboard (FR-22): acquisition/activation/deliverability/
     * monetization KPIs + trend charts over a 7/30/90-day window (default 30).
     * An absent or invalid `days` param degrades to the default rather than
     * erroring.
     */
    public function overview(Request $request, MetricsService $metrics, OverviewMetrics $overview): Response
    {
        $days = (int) $request->query('days', (string) self::DEFAULT_WINDOW);

        if (! in_array($days, MetricsService::ALLOWED_WINDOWS, true)) {
            $days = self::DEFAULT_WINDOW;
        }

        return Inertia::render('Admin/Overview', $overview->build($metrics->resolveWindow($days)));
    }

    /**
     * Users explorer (FR-23): a paginated, searchable, read-only list with each
     * user's plan/confirmation, active-trip count, last login, and whether they
     * requested a sample — all eager-loaded via correlated subqueries (no N+1).
     */
    public function users(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));

        $users = User::query()
            ->when($search !== '', fn ($query) => $query->where('email', 'like', '%'.$search.'%'))
            ->withCount(['trips as active_trips_count' => fn ($query) => $query->where('status', Trip::STATUS_ACTIVE)])
            ->withMax('loginTokens as last_login_at', 'consumed_at')
            ->withExists('sampleRequests as has_sample_request')
            ->orderByDesc('id')
            ->paginate(self::USERS_PER_PAGE)
            ->withQueryString()
            ->through(function (User $user): array {
                // Aggregate aliases from withCount/withMax/withExists are dynamic
                // attributes (not declared on the model); read via getAttribute.
                $lastLogin = $user->getAttribute('last_login_at');

                return [
                    'id' => $user->id,
                    'email' => $user->email,
                    'plan' => $user->plan,
                    'confirmed' => $user->hasConfirmedEmail(),
                    'created_at' => $user->created_at->toDateString(),
                    'active_trips_count' => (int) $user->getAttribute('active_trips_count'),
                    'last_login_at' => is_string($lastLogin)
                        ? CarbonImmutable::parse($lastLogin)->toDateString()
                        : null,
                    'has_sample_request' => (bool) $user->getAttribute('has_sample_request'),
                ];
            });

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'filters' => ['search' => $search],
        ]);
    }

    /**
     * Email health & daily-run liveness (FR-24): send health from `email_logs`
     * (AD-9) over a 7/30/90-day window plus the last `digests:run` liveness
     * snapshot (AD-14). Opens/bounces are deferred (not on this mail driver).
     */
    public function emails(Request $request, MetricsService $metrics, EmailHealthMetrics $emailHealth, SendProjection $projection): Response
    {
        $days = (int) $request->query('days', (string) self::DEFAULT_WINDOW);

        if (! in_array($days, MetricsService::ALLOWED_WINDOWS, true)) {
            $days = self::DEFAULT_WINDOW;
        }

        $window = $metrics->resolveWindow($days);

        return Inertia::render('Admin/Emails', [
            ...$emailHealth->build($window),
            'window' => $window->days,
            'windows' => MetricsService::ALLOWED_WINDOWS,
            'dates' => $window->dates(),
            'liveness' => Cache::get(SendDailyDigests::LAST_RUN_CACHE_KEY),
            'projection' => $projection->build(),
        ]);
    }

    /**
     * Promo analytics (FR-25): impressions, clicks, and CTR by slug and by
     * weather profile from `promo_events` (AD-18) over a 7/30/90-day window.
     * Read-only — catalog editing is Epic 8.
     */
    public function promos(Request $request, MetricsService $metrics, PromoAnalytics $analytics): Response
    {
        $days = (int) $request->query('days', (string) self::DEFAULT_WINDOW);

        if (! in_array($days, MetricsService::ALLOWED_WINDOWS, true)) {
            $days = self::DEFAULT_WINDOW;
        }

        $window = $metrics->resolveWindow($days);

        return Inertia::render('Admin/Promos', [
            ...$analytics->build($window),
            'window' => $window->days,
            'windows' => MetricsService::ALLOWED_WINDOWS,
        ]);
    }

    /**
     * Sample activity & acquisition (FR-25): sample requests over time, top
     * destinations, and sample→confirmed-signup conversion over a 7/30/90-day
     * window. Read-only.
     */
    public function samples(Request $request, MetricsService $metrics, SampleFunnelMetrics $funnel): Response
    {
        $days = (int) $request->query('days', (string) self::DEFAULT_WINDOW);

        if (! in_array($days, MetricsService::ALLOWED_WINDOWS, true)) {
            $days = self::DEFAULT_WINDOW;
        }

        $window = $metrics->resolveWindow($days);

        return Inertia::render('Admin/Samples', [
            ...$funnel->build($window),
            'window' => $window->days,
            'windows' => MetricsService::ALLOWED_WINDOWS,
            'dates' => $window->dates(),
        ]);
    }

    /**
     * Trip/send monitoring (FR-13, AD-9) — every Trip across users with its owner,
     * latest forecast snapshot reference, and per-Trip Email Log. Read-only.
     */
    public function monitoring(): Response
    {
        $trips = Trip::query()
            ->with([
                'user',
                'emailLogs' => fn ($query) => $query->orderByDesc('send_date'),
                'adminEmailSends' => fn ($query) => $query->orderByDesc('created_at')->limit(10),
            ])
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Admin/Monitoring', [
            'trips' => $trips->map(fn (Trip $trip): array => [
                'id' => $trip->id,
                'owner' => $trip->user->email,
                'destination_raw' => $trip->destination_raw,
                'canonical_place_name' => $trip->canonical_place_name,
                'departure_date' => $trip->departure_date->toDateString(),
                'return_date' => $trip->return_date->toDateString(),
                'status' => $trip->status,
                'owner_confirmed' => $trip->user->hasConfirmedEmail(),
                'owner_opted_out' => (bool) $trip->user->email_opted_out,
                'adminSends' => $trip->adminEmailSends->map(fn (AdminEmailSend $send): array => [
                    'recipient' => $send->recipient,
                    'status' => $send->status,
                    'created_at' => $send->created_at?->toDateTimeString(),
                ])->all(),
                'latestSnapshot' => $this->latestSnapshot($trip),
                'emailLogs' => $trip->emailLogs->map(fn (EmailLog $log): array => [
                    'send_date' => $log->send_date->toDateString(),
                    'status' => $log->status,
                    'failure_reason' => $log->failure_reason,
                ])->all(),
            ])->all(),
        ]);
    }

    /**
     * The most recent send as a compact reference, or null if the trip has never
     * sent. `emailLogs` is already ordered send_date-desc.
     *
     * @return array{send_date: string, status: string}|null
     */
    private function latestSnapshot(Trip $trip): ?array
    {
        $latest = $trip->emailLogs->first();

        if ($latest === null) {
            return null;
        }

        return [
            'send_date' => $latest->send_date->toDateString(),
            'status' => $latest->status,
        ];
    }
}
