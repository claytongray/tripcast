<?php

use Symfony\Component\Finder\Finder;

// FR-29 — the tripcast brand mark replaces all starter-kit branding. These are
// rot-guards over the component source tree; the draw-in animation itself is
// browser-verified.
it('contains no starter-kit branding anywhere in the frontend source', function () {
    $finder = Finder::create()
        ->in(resource_path('js'))
        ->files()
        ->contains('Laravel Starter Kit');

    expect(iterator_count($finder))->toBe(0);
});

it('replaces the starter-kit logo components with BrandMark', function () {
    expect(file_exists(resource_path('js/components/BrandMark.vue')))->toBeTrue()
        ->and(file_exists(resource_path('js/components/AppLogo.vue')))->toBeFalse()
        ->and(file_exists(resource_path('js/components/AppLogoIcon.vue')))->toBeFalse();
});
