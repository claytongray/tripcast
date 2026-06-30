<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The authenticated account settings page (Spec A). Thin controller: show the
 * account's editable preferences and persist the temperature unit. Email is
 * read-only; account deletion, billing, and email changes are out of scope.
 */
class SettingsController extends Controller
{
    /**
     * Show the settings page: the account email (read-only) and the current
     * temperature unit.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Settings', [
            'email' => $user->email,
            'temperatureUnit' => $user->temperature_unit,
        ]);
    }

    /**
     * Save the temperature unit — the one preference editable here.
     */
    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $request->user()->update([
            'temperature_unit' => $request->validated('temperature_unit'),
        ]);

        return back()->with('status', 'Your preferences are saved.');
    }
}
