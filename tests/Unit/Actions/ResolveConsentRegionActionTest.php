<?php

declare(strict_types=1);

use Capell\Insights\Actions\ResolveConsentRegionAction;
use Capell\Insights\Enums\InsightsConsentRegion;
use Capell\Insights\Settings\InsightsSettings;
use Capell\Insights\Support\Consent\ConsentRegionResolver;
use Torann\GeoIP\GeoIP;

beforeEach(function (): void {
    config()->set('geoip.service');
    $geoip = Mockery::mock(GeoIP::class);
    $geoip->shouldReceive('config')->with('cache', 'none')->andReturn('none');
    $geoip->shouldNotReceive('getService');
    app()->instance('geoip', $geoip);
});

it('uses the uk or europe config fallback when GeoIP is not configured', function (): void {
    config()->set('capell-insights.default_consent_region', 'uk_or_europe');

    expect(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::UkOrEurope);
});

it('uses the outside uk or europe config fallback when GeoIP is not configured', function (): void {
    config()->set('capell-insights.default_consent_region', 'outside_uk_or_europe');

    expect(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::OutsideUkOrEurope);
});

it('uses the configured fallback when GeoIP is not bound', function (): void {
    config()->set('geoip.service', 'missing');
    config()->set('capell-insights.default_consent_region', 'outside_uk_or_europe');

    app()->offsetUnset('geoip');

    expect(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::OutsideUkOrEurope);
});

it('returns unknown when location is invalid or missing', function (): void {
    config()->set('capell-insights.default_consent_region');

    $resolver = resolve(ConsentRegionResolver::class);

    expect(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::Unknown)
        ->and($resolver->resolveFromLocation(null))->toBe(InsightsConsentRegion::Unknown)
        ->and($resolver->resolveFromLocation(['country' => null]))->toBe(InsightsConsentRegion::Unknown);
});

it('maps uk and europe country codes to uk or europe consent region', function (string $countryCode): void {
    config()->set('capell-insights.default_consent_region');

    $resolver = resolve(ConsentRegionResolver::class);

    expect($resolver->resolveFromLocation(['iso_code' => $countryCode]))
        ->toBe(InsightsConsentRegion::UkOrEurope);
})->with(['GB', 'FR', 'NO', 'CH']);

it('maps non-listed country codes to outside uk or europe consent region', function (): void {
    config()->set('capell-insights.default_consent_region');

    $resolver = resolve(ConsentRegionResolver::class);

    expect($resolver->resolveFromLocation(['iso_code' => 'US']))
        ->toBe(InsightsConsentRegion::OutsideUkOrEurope);
});

it('prefers the saved admin consent region over the config fallback', function (): void {
    config()->set('capell-insights.default_consent_region');

    $settings = resolve(InsightsSettings::class);
    $settings->default_consent_region = InsightsConsentRegion::OutsideUkOrEurope->value;
    $settings->save();

    expect(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::OutsideUkOrEurope);

    config()->set('capell-insights.default_consent_region', InsightsConsentRegion::UkOrEurope->value);

    expect(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::OutsideUkOrEurope);
});
