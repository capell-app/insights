<?php

declare(strict_types=1);

use Capell\Insights\Actions\ResolveConsentRegionAction;
use Capell\Insights\Actions\ResolveInsightsConsentPolicyAction;
use Capell\Insights\Enums\InsightsConsentRegion;
use Capell\Insights\Settings\InsightsSettings;
use Capell\Insights\Support\Consent\ConsentRegionResolver;
use Capell\Insights\Tests\Fixtures\FailingGeoIpService;
use Capell\Insights\Tests\Fixtures\ReturningGeoIpService;
use Illuminate\Cache\CacheManager;
use Illuminate\Http\Request;
use Torann\GeoIP\GeoIP;
use Torann\GeoIP\Location;

beforeEach(function (): void {
    config()->set('capell-insights.default_consent_region');
    config()->set('geoip.default_location', []);
    config()->set('geoip.service', 'failing');

    app()->instance('request', Request::create('/capell/insights/consent-policy', server: ['REMOTE_ADDR' => '8.8.8.8']));

    $geoip = Mockery::mock(GeoIP::class);
    $geoip->shouldReceive('config')->with('cache', 'none')->andReturn('none');
    $geoip->shouldReceive('getService->locate')->andReturn(null);
    app()->instance('geoip', $geoip);
});

it('requires consent after a caught provider exception regardless of saved or configured fallback', function (?string $saved, ?string $fallback): void {
    config()->set('capell-insights.default_consent_region', $fallback);
    if ($saved !== null) {
        $settings = resolve(InsightsSettings::class);
        $settings->default_consent_region = $saved;
        $settings->save();
    }

    $geoip = new GeoIP([
        'cache' => 'none',
        'cache_tags' => [],
        'log_failures' => false,
        'service' => 'failing',
        'services' => ['failing' => ['class' => FailingGeoIpService::class]],
    ], resolve(CacheManager::class));
    app()->instance('geoip', $geoip);

    $service = $geoip->getService();
    $this->assertInstanceOf(FailingGeoIpService::class, $service);
    $location = $geoip->getLocation('8.8.8.8');

    expect($service->lookups)->toBe(1)
        ->and($location::class)->toBe(Location::class)
        ->and($location->default)->toBeTrue()
        ->and($location->iso_code)->toBe('US')
        ->and(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::Unknown)
        ->and(ResolveInsightsConsentPolicyAction::run()->consentRequired)->toBeTrue()
        ->and($service->lookups)->toBe(3);
})->with([
    'strict configured fallback' => [null, 'uk_or_europe'],
    'permissive configured fallback' => [null, 'outside_uk_or_europe'],
    'strict saved fallback' => ['uk_or_europe', 'outside_uk_or_europe'],
    'permissive saved fallback' => ['outside_uk_or_europe', 'uk_or_europe'],
    'no fallback' => [null, null],
]);

it('rejects a real GeoIP fallback regardless of default configuration fields', function (array $defaultLocation): void {
    config()->set('geoip.default_location', $defaultLocation);
    config()->set('capell-insights.default_consent_region', 'uk_or_europe');

    $geoip = new GeoIP([
        'cache' => 'none',
        'cache_tags' => [],
        'log_failures' => false,
        'default_location' => $defaultLocation,
        'service' => 'failing',
        'services' => ['failing' => ['class' => FailingGeoIpService::class]],
    ], resolve(CacheManager::class));
    app()->instance('geoip', $geoip);
    $service = $geoip->getService();
    $this->assertInstanceOf(FailingGeoIpService::class, $service);
    $location = $geoip->getLocation('8.8.8.8');

    expect($service->lookups)->toBe(1)
        ->and($location->default)->toBe($defaultLocation['default'] ?? true)
        ->and($location->iso_code)->toBe('US')
        ->and($location->city)->toBe($defaultLocation['city'] ?? 'New Haven')
        ->and(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::Unknown)
        ->and(ResolveInsightsConsentPolicyAction::run()->consentRequired)->toBeTrue()
        ->and($service->lookups)->toBe(3);
})->with([
    'partial location with false flag' => [['city' => 'Fallback city', 'default' => false]],
    'only false default flag' => [['default' => false]],
    'only IP' => [['ip' => '8.8.8.8']],
    'only cached flag' => [['cached' => true]],
    'empty default' => [[]],
    'metadata with false default flag' => [['default' => false, 'ip' => '8.8.8.8', 'cached' => true]],
]);

it('observes the real GeoIP provider result before fallback hydration or flag rewriting', function (string $result, InsightsConsentRegion $region, bool $consentRequired): void {
    $defaultLocation = ['iso_code' => 'US', 'city' => 'New Haven', 'default' => false];
    config()->set('geoip.default_location', $defaultLocation);
    config()->set('capell-insights.default_consent_region', 'uk_or_europe');

    $geoip = new GeoIP([
        'cache' => 'none',
        'cache_tags' => [],
        'log_failures' => false,
        'default_location' => $defaultLocation,
        'service' => 'returning',
        'services' => ['returning' => ['class' => ReturningGeoIpService::class, 'result' => $result]],
    ], resolve(CacheManager::class));
    app()->instance('geoip', $geoip);
    $service = $geoip->getService();
    $this->assertInstanceOf(ReturningGeoIpService::class, $service);

    expect(ResolveConsentRegionAction::run())->toBe($region)
        ->and(ResolveInsightsConsentPolicyAction::run()->consentRequired)->toBe($consentRequired)
        ->and($service->lookups)->toBe(2);
})->with([
    'no result' => ['missing', InsightsConsentRegion::Unknown, true],
    'false result' => ['false', InsightsConsentRegion::Unknown, true],
    'default flagged result' => ['default', InsightsConsentRegion::Unknown, true],
    'successful lookup matching configured default fields' => ['success', InsightsConsentRegion::OutsideUkOrEurope, false],
]);

it('rejects default-flagged GeoIP locations', function (mixed $location): void {
    expect(data_get($location, 'iso_code'))->toBe('US')
        ->and(resolve(ConsentRegionResolver::class)->resolveFromLocation($location))
        ->toBe(InsightsConsentRegion::Unknown);
})->with([
    'array' => fn (): array => ['iso_code' => 'US', 'default' => true],
    'object' => fn (): object => (object) ['iso_code' => 'US', 'default' => true],
    'library location' => fn (): Location => new Location(['iso_code' => 'US', 'default' => true]),
]);

it('accepts successful provider locations even when their fields match the configured default', function (mixed $location): void {
    config()->set('geoip.default_location', [
        'ip' => '127.0.0.0',
        'iso_code' => 'US',
        'city' => 'New Haven',
        'default' => false,
        'cached' => false,
    ]);
    config()->set('capell-insights.default_consent_region', 'uk_or_europe');

    $geoip = Mockery::mock(GeoIP::class);
    $geoip->shouldReceive('config')->with('cache', 'none')->andReturn('none');
    $geoip->shouldReceive('getService->locate')->once()->andReturn($location);
    app()->instance('geoip', $geoip);

    expect(data_get($location, 'iso_code'))->toBe('US')
        ->and(resolve(ConsentRegionResolver::class)->resolveFromLocation($location))->toBe(InsightsConsentRegion::OutsideUkOrEurope)
        ->and(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::OutsideUkOrEurope);
})->with([
    'array without flag' => fn (): array => ['ip' => '8.8.8.8', 'iso_code' => 'US', 'city' => 'New Haven', 'cached' => true],
    'object without flag' => fn (): object => (object) ['iso_code' => 'US', 'city' => 'New Haven'],
    'library location with false flag' => fn (): Location => new Location(['iso_code' => 'US', 'city' => 'New Haven', 'default' => false]),
]);

it('accepts a real GeoIP location that only shares the default country', function (): void {
    config()->set('geoip.default_location', ['iso_code' => 'US', 'city' => 'New Haven']);

    expect(resolve(ConsentRegionResolver::class)->resolveFromLocation(new Location(['iso_code' => 'US', 'city' => 'Boston', 'default' => false])))
        ->toBe(InsightsConsentRegion::OutsideUkOrEurope);
});

it('accepts an authenticated edge country even when it matches the GeoIP default', function (): void {
    config()->set('geoip.default_location', ['iso_code' => 'US']);
    request()->server->set('CAPELL_EDGE_COUNTRY', 'US');

    expect(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::OutsideUkOrEurope);
});

it('rejects a server country imported only from the process environment', function (string $parameter): void {
    config()->set('capell-insights.edge_country_server_parameter', $parameter);
    $previous = getenv($parameter, true);

    try {
        expect(putenv($parameter . '=US'))->toBeTrue();
        // FPM imports process variables into the request server bag when params are absent.
        request()->server->set($parameter, getenv($parameter, true));

        expect(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::Unknown);

        config()->set('capell-insights.default_consent_region', 'uk_or_europe');
        expect(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::Unknown);
    } finally {
        putenv($previous === false ? $parameter : $parameter . '=' . $previous);
    }
})->with(['default name' => 'CAPELL_EDGE_COUNTRY', 'configured name' => 'ORIGIN_COUNTRY']);

it('rejects ambiguous edge provenance even when an environment value is empty or differs', function (string $environment): void {
    $parameter = 'CAPELL_EDGE_COUNTRY';
    $previous = getenv($parameter, true);

    try {
        expect(putenv($parameter . '=' . $environment))->toBeTrue();
        request()->server->set($parameter, 'US');

        expect(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::Unknown);
    } finally {
        putenv($previous === false ? $parameter : $parameter . '=' . $previous);
    }
})->with(['empty environment' => '', 'conflicting environment' => 'DE']);
