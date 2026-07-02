<?php

namespace App\Http\Requests;

use App\Models\PromoItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates catalog item create/update (Story 8.3, FR-26).
 *
 * Defense-in-depth: `authorize()` re-checks the admin Gate on top of the route
 * group's `can:admin` (AD-12). `slug` is the immutable attribution key (AD-18) —
 * uniqueness spans soft-deleted rows because {@see Rule::unique} queries the
 * table directly (no SoftDeletes global scope), and the controller drops any
 * posted slug on update so it is set-once. `weather_profile` validation allows
 * the full taxonomy (incl. legacy `mild`) so an existing `mild` row stays
 * editable; the create form is what omits `mild` from the offered options.
 */
class PromoItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin') ?? false;
    }

    /**
     * Normalize the checkbox boolean the Vue form may post as a string.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('is_active')) {
            $this->merge([
                'is_active' => $this->boolean('is_active'),
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('promo_items', 'slug')->ignore($this->route('promo_item')),
            ],
            'label' => ['required', 'string', 'max:255'],
            'image_url' => ['required', 'string', 'url:https', 'max:2048'],
            'url' => ['required', 'string', 'url:http,https', 'max:2048'],
            'merchant' => ['required', Rule::in(PromoItem::MERCHANTS)],
            'weather_profile' => ['required', Rule::in($this->selectableProfiles())],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
            // `featured_from` is required whenever a `featured_to` is given —
            // otherwise `scopeFeaturedOn` (which needs a start) silently never
            // matches, and `after_or_equal:featured_from` is a no-op on null.
            'featured_from' => ['nullable', 'required_with:featured_to', 'date_format:Y-m-d'],
            'featured_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:featured_from'],
        ];
    }

    /**
     * The weather profiles this request may set. `mild` is neutral/legacy and no
     * longer weather-selectable (FR-26): it is allowed only on **update** (so a
     * legacy `mild` row stays editable) and forbidden on **create** (no new
     * `mild` items). On create there is no bound `promo_item` route param.
     *
     * @return list<string>
     */
    private function selectableProfiles(): array
    {
        if ($this->route('promo_item') !== null) {
            return PromoItem::PROFILES;
        }

        return array_values(array_filter(
            PromoItem::PROFILES,
            fn (string $profile): bool => $profile !== PromoItem::PROFILE_MILD,
        ));
    }

    /**
     * Calm microcopy (EXPERIENCE.md voice). The slug-collision hint nudges toward
     * restoring a retired item rather than force-deleting to reuse the slug.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.unique' => 'That slug is already in use — it may belong to a retired item. Pick a different slug.',
            'image_url.url' => 'The image URL must be a full https:// link.',
            'url.url' => 'The product URL must be a full http(s):// link.',
            'featured_to.after_or_equal' => 'The Featured end date is before its start date — check the window.',
        ];
    }

    /**
     * The validated attributes with `slug` removed — used on update so the
     * attribution key is set-once (AD-18) even if a disabled field is re-enabled.
     *
     * @return array<string, mixed>
     */
    public function validatedExceptSlug(): array
    {
        return collect($this->validated())->except('slug')->all();
    }
}
