<?php

namespace App\Http\Controllers;

use App\Models\EmailLog;
use App\Models\Trip;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin monitoring view (FR-13). Read-only: every Trip across users with its
 * owner, latest forecast snapshot reference, and per-Trip Email Log — read from
 * `email_logs`, the source of truth (AD-9). Guarded by the single `admin` Gate
 * (AD-12) on the route; no mutations, no analytics.
 */
class AdminController extends Controller
{
    public function index(): Response
    {
        $trips = Trip::query()
            ->with([
                'user',
                'emailLogs' => fn ($query) => $query->orderByDesc('send_date'),
            ])
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Admin', [
            'trips' => $trips->map(fn (Trip $trip): array => [
                'id' => $trip->id,
                'owner' => $trip->user->email,
                'destination_raw' => $trip->destination_raw,
                'canonical_place_name' => $trip->canonical_place_name,
                'departure_date' => $trip->departure_date->toDateString(),
                'return_date' => $trip->return_date->toDateString(),
                'status' => $trip->status,
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
