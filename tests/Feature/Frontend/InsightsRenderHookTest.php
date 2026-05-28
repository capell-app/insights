<?php

declare(strict_types=1);

use Capell\Frontend\Data\RenderHookContext;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookRegistry;

it('injects the frontend insights tracker at the end of the body', function (): void {
    config()->set('capell-insights.ignored_selectors', [
        '[data-capell-insights-ignore]',
        '[wire\\:click]',
    ]);

    /** @var RenderHookRegistry<RenderHookContext> $registry */
    $registry = resolve(RenderHookRegistry::class);

    $output = $registry->renderAll(RenderHookLocation::BodyEnd);

    expect($output)
        ->toContain('data-capell-insights-tracker')
        ->toContain(route('capell-insights.events'))
        ->toContain(route('capell-insights.consent'))
        ->toContain('"ignoredSelectors":["[data-capell-insights-ignore]","[wire\\\\:click]"]');
});

it('can inject a signed event beacon url', function (): void {
    config()->set('capell-insights.require_signed_beacons', true);

    /** @var RenderHookRegistry<RenderHookContext> $registry */
    $registry = resolve(RenderHookRegistry::class);

    $output = $registry->renderAll(RenderHookLocation::BodyEnd);

    expect($output)
        ->toContain('data-capell-insights-tracker')
        ->toContain('signature=')
        ->toContain(route('capell-insights.consent'));
});
