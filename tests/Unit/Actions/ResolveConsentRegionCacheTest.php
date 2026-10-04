<?php

declare(strict_types=1);

use Capell\Insights\Actions\ResolveConsentRegionAction;
use Capell\Insights\Actions\ResolveInsightsConsentPolicyAction;
use Capell\Insights\Enums\InsightsConsentRegion;
use Capell\Insights\Tests\Fixtures\ReturningGeoIpService;
use Illuminate\Cache\CacheManager;
use Illuminate\Http\Request;
use PHPUnit\Framework\Assert;
use Torann\GeoIP\GeoIP;

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    config()->set('geoip.service', 'returning');
    config()->set('capell-insights.require_consent_for_all_regions', false);

    app()->instance('request', Request::create('/capell/insights/consent-policy', server: ['REMOTE_ADDR' => '8.8.8.8']));
});

it('caches validated country successes by visitor IP using the GeoIP cache', function (string $mode, bool $tagged): void {
    $service = insightsCachedGeoIp($mode, tags: $tagged ? ['torann-geoip-location'] : []);

    expect(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::OutsideUkOrEurope)
        ->and(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::OutsideUkOrEurope)
        ->and($service->lookups)->toBe(1);
    $service->unavailable = true;
    expect(ResolveInsightsConsentPolicyAction::run()->consentRequired)->toBeFalse()
        ->and($service->lookups)->toBe(1);

    app()->instance('request', Request::create('/', server: ['REMOTE_ADDR' => '1.1.1.1']));
    expect(ResolveInsightsConsentPolicyAction::run()->consentRequired)->toBeTrue()
        ->and($service->lookups)->toBe(2);
})->with([
    'all' => ['all', false],
    'requesting visitor' => ['some', false],
    'tagged cache' => ['all', true],
]);

it('never caches failed invalid or default provider results', function (string $result, bool $unavailable): void {
    $service = insightsCachedGeoIp('all', $result);
    $service->unavailable = $unavailable;

    expect(ResolveInsightsConsentPolicyAction::run()->consentRequired)->toBeTrue()
        ->and(ResolveInsightsConsentPolicyAction::run()->consentRequired)->toBeTrue()
        ->and($service->lookups)->toBe(2);
})->with([
    'exception' => ['success', true],
    'missing' => ['missing', false],
    'false' => ['false', false],
    'default country' => ['default', false],
    'unrecognised country' => ['invalid', false],
]);

it('calls the provider on every lookup with caching disabled', function (): void {
    $service = insightsCachedGeoIp('none');

    expect(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::OutsideUkOrEurope)
        ->and(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::OutsideUkOrEurope)
        ->and($service->lookups)->toBe(2);
});

it('expires cached successes after the configured GeoIP lifetime', function (int $expires): void {
    $service = insightsCachedGeoIp('all', expires: $expires);
    $this->freezeTime();

    expect(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::OutsideUkOrEurope);
    $this->travel($expires - 1)->seconds();
    expect(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::OutsideUkOrEurope)
        ->and($service->lookups)->toBe(1);
    $service->unavailable = true;
    $this->travel(1)->seconds();
    expect(ResolveInsightsConsentPolicyAction::run()->consentRequired)->toBeTrue()
        ->and(ResolveInsightsConsentPolicyAction::run()->consentRequired)->toBeTrue()
        ->and($service->lookups)->toBe(3);
})->with([5, 60]);

it('does not trust the library cache which may contain hydrated defaults', function (): void {
    $service = insightsCachedGeoIp('all', 'default');
    resolve('geoip')->getLocation('8.8.8.8');

    expect(ResolveInsightsConsentPolicyAction::run()->consentRequired)->toBeTrue()
        ->and(ResolveInsightsConsentPolicyAction::run()->consentRequired)->toBeTrue()
        ->and($service->lookups)->toBe(3);
});

/** @param list<string> $tags */
function insightsCachedGeoIp(string $mode, string $result = 'success', int $expires = 30, array $tags = []): ReturningGeoIpService
{
    $geoip = new GeoIP([
        'cache' => $mode,
        'cache_tags' => $tags,
        'cache_expires' => $expires,
        'log_failures' => false,
        'service' => 'returning',
        'services' => ['returning' => ['class' => ReturningGeoIpService::class, 'result' => $result]],
    ], resolve(CacheManager::class));
    app()->instance('geoip', $geoip);
    $service = $geoip->getService();
    Assert::assertInstanceOf(ReturningGeoIpService::class, $service);

    return $service;
}
