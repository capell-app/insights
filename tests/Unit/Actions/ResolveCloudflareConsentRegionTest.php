<?php

declare(strict_types=1);

use Capell\Insights\Actions\ResolveConsentRegionAction;
use Capell\Insights\Enums\InsightsConsentRegion;
use Capell\Insights\Support\Consent\ConsentRegionResolver;
use Illuminate\Http\Request;
use Torann\GeoIP\GeoIP;

beforeEach(function (): void {
    config()->set('capell-insights.default_consent_region');
    config()->set('geoip.service', 'configured');

    $geoip = Mockery::mock(GeoIP::class);
    $geoip->shouldReceive('config')->with('cache', 'none')->andReturn('none');
    $geoip->shouldReceive('getService->locate')->andReturn(null);
    app()->instance('geoip', $geoip);
});

it('reads a recognised country from the nginx server parameter', function (string $country, InsightsConsentRegion $region): void {
    app()->instance('request', insightsEdgeCountryRequest($country));

    expect(ResolveConsentRegionAction::run())->toBe($region);
})->with([
    'US' => ['US', InsightsConsentRegion::OutsideUkOrEurope],
    'GB' => ['GB', InsightsConsentRegion::UkOrEurope],
    'DE' => ['DE', InsightsConsentRegion::UkOrEurope],
]);

it('fails closed on a GeoIP exception even with a permissive default', function (): void {
    config()->set('capell-insights.default_consent_region', InsightsConsentRegion::OutsideUkOrEurope->value);
    $geoip = Mockery::mock(GeoIP::class);
    $geoip->shouldReceive('config')->with('cache', 'none')->andReturn('none');
    $geoip->shouldReceive('getService->locate')->once()->andThrow(new RuntimeException('Geography unavailable'));
    app()->instance('geoip', $geoip);
    app()->instance('request', Request::create('/capell/insights/consent-policy', server: ['REMOTE_ADDR' => '203.0.113.55']));

    expect(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::Unknown);
});

it('rejects unrecognised or malformed edge country values', function (mixed $country): void {
    app()->instance('request', insightsEdgeCountryRequest($country));

    expect(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::Unknown);
})->with([
    'unknown' => ['XX'],
    'Tor' => ['T1'],
    'reserved' => ['ZZ'],
    'unassigned' => ['AA'],
    'non-ISO alias' => ['UK'],
    'user-assigned' => ['XK'],
    'empty' => [''],
    'lowercase' => ['us'],
    'whitespace' => [' US '],
    'multiple countries' => ['US, DE'],
    'duplicate country' => ['US, US'],
    'multiple values' => [['US', 'DE']],
    'single value array' => [['US']],
    'newline' => ["US\n"],
    'non-string' => [123],
]);

it('prefers the server country over GeoIP and the configured default', function (): void {
    config()->set('capell-insights.default_consent_region', InsightsConsentRegion::UkOrEurope->value);
    $geoip = Mockery::mock(GeoIP::class);
    $geoip->shouldReceive('config')->with('cache', 'none')->andReturn('none');
    $geoip->shouldNotReceive('getService');
    app()->instance('geoip', $geoip);
    app()->instance('request', insightsEdgeCountryRequest('US'));

    expect(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::OutsideUkOrEurope);
});

it('uses the configured server parameter instead of the default name', function (): void {
    config()->set('capell-insights.edge_country_server_parameter', 'ORIGIN_COUNTRY');
    $request = insightsEdgeCountryRequest('US');
    $request->server->set('ORIGIN_COUNTRY', 'DE');

    app()->instance('request', $request);

    expect(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::UkOrEurope);
});

it('ignores country and forwarding HTTP headers without a server country', function (string $peer): void {
    $request = Request::create('/capell/insights/consent-policy', server: [
        'REMOTE_ADDR' => $peer,
        'HTTP_CF_IPCOUNTRY' => 'US',
        'HTTP_CAPELL_EDGE_COUNTRY' => 'US',
        'HTTP_X_FORWARDED_FOR' => '173.245.48.10',
        'HTTP_CF_CONNECTING_IP' => '173.245.48.10',
    ]);
    app()->instance('request', $request);

    expect(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::Unknown);
})->with(['visitor' => '203.0.113.55', 'edge IPv4' => '173.245.48.10', 'edge IPv6' => '2606:4700::1234']);

it('cannot configure an HTTP header as the trusted server parameter', function (): void {
    config()->set('capell-insights.edge_country_server_parameter', 'HTTP_CF_IPCOUNTRY');
    $request = Request::create('/capell/insights/consent-policy', server: [
        'REMOTE_ADDR' => '173.245.48.10',
        'HTTP_CF_IPCOUNTRY' => 'US',
    ]);
    app()->instance('request', $request);

    expect(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::Unknown);
});

it('ignores absent or invalid edge geography and tries GeoIP before the default', function (mixed $country): void {
    config()->set('capell-insights.default_consent_region', InsightsConsentRegion::UkOrEurope->value);
    $geoip = Mockery::mock(GeoIP::class);
    $geoip->shouldReceive('config')->with('cache', 'none')->andReturn('none');
    $geoip->shouldReceive('getService->locate')->once()->with('203.0.113.55')->andReturn(['iso_code' => 'US']);
    app()->instance('geoip', $geoip);
    app()->instance('request', insightsEdgeCountryRequest($country));

    expect(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::OutsideUkOrEurope);
})->with([
    'absent' => [null],
    'unknown' => ['XX'],
    'Tor' => ['T1'],
    'reserved' => ['ZZ'],
    'empty' => [''],
    'malformed' => ['US, DE'],
    'multiple values' => [['US', 'DE']],
]);

it('fails closed when GeoIP returns no recognised country despite a permissive default', function (mixed $location): void {
    config()->set('capell-insights.default_consent_region', InsightsConsentRegion::OutsideUkOrEurope->value);
    $geoip = Mockery::mock(GeoIP::class);
    $geoip->shouldReceive('config')->with('cache', 'none')->andReturn('none');
    $geoip->shouldReceive('getService->locate')->once()->andReturn($location);
    app()->instance('geoip', $geoip);
    app()->instance('request', insightsEdgeCountryRequest(null));

    expect(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::Unknown);
})->with([
    'absent' => [null],
    'unknown' => [['iso_code' => 'XX']],
    'reserved' => [['iso_code' => 'ZZ']],
    'malformed' => [['iso_code' => 'US, DE']],
]);

it('fails closed without an edge country or a configured default', function (): void {
    app()->instance('request', insightsEdgeCountryRequest(null));

    expect(ResolveConsentRegionAction::run())->toBe(InsightsConsentRegion::Unknown);
});

it('rejects unrecognised geography from location providers too', function (): void {
    $resolver = resolve(ConsentRegionResolver::class);

    expect($resolver->resolveFromLocation(['iso_code' => 'ZZ']))->toBe(InsightsConsentRegion::Unknown)
        ->and($resolver->resolveFromLocation(['iso_code' => 'UK']))->toBe(InsightsConsentRegion::Unknown);
});

function insightsEdgeCountryRequest(mixed $country, string $visitor = '203.0.113.55'): Request
{
    $request = Request::create('/capell/insights/consent-policy', server: ['REMOTE_ADDR' => $visitor]);

    if ($country !== null) {
        $request->server->set('CAPELL_EDGE_COUNTRY', $country);
    }

    return $request;
}
