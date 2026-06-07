<?php

declare(strict_types=1);

use Capell\Frontend\Data\RenderHookContext;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Illuminate\Http\Request;

it('injects the frontend insights tracker at the end of the body', function (): void {
    config()->set('capell-insights.ignored_selectors', [
        '[data-capell-insights-ignore]',
        '[wire\\:click]',
    ]);

    /** @var RenderHookRegistry<RenderHookContext> $registry */
    $registry = resolve(RenderHookRegistry::class);

    $output = $registry->renderAll(RenderHookLocation::BodyEnd);

    expect($output)
        ->toContain('data-capell-insights-consent-banner')
        ->toContain('data-capell-insights-consent-action="accept"')
        ->toContain('data-capell-insights-tracker')
        ->toContain(route('capell-insights.events'))
        ->toContain(route('capell-insights.consent'))
        ->toContain('"ignoredSelectors":["[data-capell-insights-ignore]","[wire\\\\:click]"]');
});

it('does not inject the frontend insights tracker on ignored admin paths', function (): void {
    app()->instance('request', Request::create('/admin/pages', 'GET'));
    config()->set('capell-insights.ignored_paths', ['/admin*']);

    /** @var RenderHookRegistry<RenderHookContext> $registry */
    $registry = resolve(RenderHookRegistry::class);

    $output = $registry->renderAll(RenderHookLocation::BodyEnd);

    expect($output)
        ->not->toContain('data-capell-insights-consent-banner')
        ->not->toContain('data-capell-insights-tracker');
});

it('can inject a signed event beacon url', function (): void {
    config()->set('capell-insights.require_signed_beacons', true);

    /** @var RenderHookRegistry<RenderHookContext> $registry */
    $registry = resolve(RenderHookRegistry::class);

    $output = $registry->renderAll(RenderHookLocation::BodyEnd);

    expect($output)
        ->toContain('data-capell-insights-consent-banner')
        ->toContain('data-capell-insights-tracker')
        ->toContain('signature=')
        ->toContain(route('capell-insights.consent'));
});

it('can disable the frontend consent banner', function (): void {
    config()->set('capell-insights.consent_banner_enabled', false);

    /** @var RenderHookRegistry<RenderHookContext> $registry */
    $registry = resolve(RenderHookRegistry::class);

    $output = $registry->renderAll(RenderHookLocation::BodyEnd);

    expect($output)
        ->not->toContain('class="capell-insights-consent-banner"')
        ->not->toContain('role="dialog"')
        ->toContain('data-capell-insights-tracker');
});
