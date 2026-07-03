<?php

namespace App\Http\Controllers;

use App\Http\Requests\PromoItemRequest;
use App\Models\PromoItem;
use App\Services\Metrics\MetricsService;
use App\Services\Metrics\PromoAnalytics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catalog CRUD (Story 8.3, FR-26) — the first *mutating* admin surface. Registered
 * inside the single `['auth','can:admin']->prefix('admin')` group (AD-12), so all
 * six verbs (incl. writes) inherit the one Gate; there is no second policy.
 *
 * `slug` is the immutable attribution key (AD-18): set-once on edit (dropped from
 * the update payload) so historical `promo_events` never orphan. Retirement is a
 * reversible `is_active` toggle or a soft-delete — never a force-delete — so the
 * 8.2 `findBySlug(withTrashed)` click path keeps resolving. Per-item analytics
 * (impressions/clicks/CTR) are Story 8.5, not here.
 */
class PromoItemController extends Controller
{
    /** Default performance window (days) when none/invalid is requested. */
    private const DEFAULT_WINDOW = 30;

    /**
     * Read-only projected list of live (non-trashed) items, ordered by weather
     * profile then the rotation tiebreaker `(sort_order, slug)`, each carrying its
     * impressions/clicks/CTR over a 7/30/90-day window (Story 8.5, FR-25).
     */
    public function index(Request $request, MetricsService $metrics, PromoAnalytics $analytics): Response
    {
        $days = (int) $request->query('days', (string) self::DEFAULT_WINDOW);

        if (! in_array($days, MetricsService::ALLOWED_WINDOWS, true)) {
            $days = self::DEFAULT_WINDOW;
        }

        $window = $metrics->resolveWindow($days);
        $stats = $analytics->perSlug($window);

        $items = PromoItem::query()
            ->orderBy('weather_profile')
            ->orderBy('sort_order')
            ->orderBy('slug')
            ->get()
            ->map(fn (PromoItem $item): array => [
                ...$this->toArray($item),
                'impressions' => $stats[$item->slug]['impressions'] ?? 0,
                'clicks' => $stats[$item->slug]['clicks'] ?? 0,
                'ctr' => $stats[$item->slug]['ctr'] ?? 0.0,
            ])
            ->all();

        return Inertia::render('Admin/Catalog/Index', [
            'items' => $items,
            'profiles' => PromoItem::PROFILES,
            'merchants' => PromoItem::MERCHANTS,
            'window' => $window->days,
            'windows' => MetricsService::ALLOWED_WINDOWS,
        ]);
    }

    /**
     * New-item form. `mild` is neutral/legacy (FR-26): it never routes weather,
     * so it is omitted from the profiles a *new* item may target.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Catalog/Form', [
            'item' => null,
            'slugLocked' => false,
            'profiles' => $this->selectableProfiles(),
            'merchants' => PromoItem::MERCHANTS,
        ]);
    }

    public function store(PromoItemRequest $request): RedirectResponse
    {
        PromoItem::create($request->validated());

        return redirect()->route('admin.promo-items.index')
            ->with('status', 'Catalog item added.');
    }

    /**
     * Edit form. The full taxonomy is offered (incl. `mild`) so a legacy `mild`
     * row shows its current value; the slug is locked (set-once, AD-18).
     */
    public function edit(PromoItem $promoItem): Response
    {
        return Inertia::render('Admin/Catalog/Form', [
            'item' => $this->toArray($promoItem),
            'slugLocked' => true,
            'profiles' => PromoItem::PROFILES,
            'merchants' => PromoItem::MERCHANTS,
        ]);
    }

    public function update(PromoItemRequest $request, PromoItem $promoItem): RedirectResponse
    {
        // slug is set-once — drop any posted value so attribution never re-points.
        $promoItem->update($request->validatedExceptSlug());

        return redirect()->route('admin.promo-items.index')
            ->with('status', 'Catalog item saved.');
    }

    /**
     * Retire (soft-delete). The row leaves the list but `findBySlug(withTrashed)`
     * still resolves it for live click links (AD-18). Never force-deletes.
     */
    public function destroy(PromoItem $promoItem): RedirectResponse
    {
        $promoItem->delete();

        return redirect()->route('admin.promo-items.index')
            ->with('status', 'Catalog item retired.');
    }

    /**
     * A stable display shape for a catalog item — dates normalized to `Y-m-d`
     * (or null) so the Vue date inputs and list read cleanly.
     *
     * @return array<string, mixed>
     */
    private function toArray(PromoItem $item): array
    {
        return [
            'id' => $item->id,
            'slug' => $item->slug,
            'label' => $item->label,
            'description' => $item->description,
            'image_url' => $item->image_url,
            'url' => $item->url,
            'merchant' => $item->merchant,
            'weather_profile' => $item->weather_profile,
            'is_active' => $item->is_active,
            'featured_from' => $item->featured_from?->format('Y-m-d'),
            'featured_to' => $item->featured_to?->format('Y-m-d'),
            'sort_order' => $item->sort_order,
        ];
    }

    /**
     * The weather profiles a *new* item may target: the fixed taxonomy minus the
     * neutral/legacy `mild` key, which is no longer weather-selectable (FR-26).
     *
     * @return list<string>
     */
    private function selectableProfiles(): array
    {
        return array_values(array_filter(
            PromoItem::PROFILES,
            fn (string $profile): bool => $profile !== PromoItem::PROFILE_MILD,
        ));
    }
}
