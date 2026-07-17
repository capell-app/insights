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
        ->toContain(route('capell-insights.events', [], false))
        ->toContain(route('capell-insights.consent', [], false))
        ->toContain('"consentRequired":true')
        ->toContain('"ignoredSelectors":["[data-capell-insights-ignore]","[wire\\\\:click]"]');
});

it('does not require consent up front outside the UK and Europe', function (): void {
    config()->set('capell-insights.default_consent_region', 'outside_uk_or_europe');

    /** @var RenderHookRegistry<RenderHookContext> $registry */
    $registry = resolve(RenderHookRegistry::class);

    $output = $registry->renderAll(RenderHookLocation::BodyEnd);

    expect($output)->toContain('"consentRequired":false');
});

it('does not inject the frontend insights tracker on ignored admin paths', function (): void {
    app()->instance('request', Request::create('/admin/pages', Symfony\Component\HttpFoundation\Request::METHOD_GET));
    config()->set('capell-insights.ignored_paths', ['/admin*']);

    /** @var RenderHookRegistry<RenderHookContext> $registry */
    $registry = resolve(RenderHookRegistry::class);

    $output = $registry->renderAll(RenderHookLocation::BodyEnd);

    expect($output)
        ->not->toContain('data-capell-insights-consent-banner')
        ->not->toContain('data-capell-insights-tracker');
});

it('does not serialize signed event beacon urls into public html', function (): void {
    config()->set('capell-insights.require_signed_beacons', true);

    /** @var RenderHookRegistry<RenderHookContext> $registry */
    $registry = resolve(RenderHookRegistry::class);

    $output = $registry->renderAll(RenderHookLocation::BodyEnd);

    expect($output)
        ->not->toContain('data-capell-insights-consent-banner')
        ->not->toContain('data-capell-insights-tracker')
        ->not->toContain('signature=');

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
