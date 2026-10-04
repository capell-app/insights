<?php

declare(strict_types=1);

use Capell\HtmlCache\Support\Cache\PublicResponseCachePolicy;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Torann\GeoIP\GeoIP;

it('resolves the existing regional policy through a stateless private GET', function (?string $region, bool $forceConsent, bool $required): void {
    config()->set('capell-insights.default_consent_region', $region);
    config()->set('capell-insights.require_consent_for_all_regions', $forceConsent);
    config()->set('geoip.service');

    $geoip = Mockery::mock(GeoIP::class);
    $geoip->shouldReceive('config')->with('cache', 'none')->andReturn('none');
    $geoip->shouldNotReceive('getService');
    app()->instance('geoip', $geoip);

    $response = $this->getJson('/capell/insights/consent-policy');

    $response->assertOk()->assertExactJson(['consent_required' => $required]);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store')
        ->and($response->headers->getCookies())->toBe([]);

    $route = Route::getRoutes()->getByName('capell-insights.consent-policy');
    $this->assertInstanceOf(RoutingRoute::class, $route);
    $middleware = resolve(Router::class)->gatherRouteMiddleware($route);
    expect($middleware)->not->toContain(StartSession::class)
        ->not->toContain(AddQueuedCookiesToResponse::class);
})->with([
    'UK and Europe' => ['uk_or_europe', false, true],
    'outside' => ['outside_uk_or_europe', false, false],
    'global requirement' => ['outside_uk_or_europe', true, true],
    'unknown' => ['unknown', false, true],
    'unavailable geography' => [null, false, true],
]);

it('requires consent on a GeoIP exception despite a permissive default', function (): void {
    config()->set('geoip.service', 'configured');
    config()->set('capell-insights.default_consent_region', 'outside_uk_or_europe');
    config()->set('capell-insights.require_consent_for_all_regions', false);

    $geoip = Mockery::mock(GeoIP::class);
    $geoip->shouldReceive('config')->with('cache', 'none')->andReturn('none');
    $geoip->shouldReceive('getService->locate')->once()->andThrow(new RuntimeException('Geography unavailable'));
    app()->instance('geoip', $geoip);
    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8']);

    $response = $this->getJson('/capell/insights/consent-policy');

    $response->assertOk()->assertExactJson(['consent_required' => true]);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store')
        ->and(resolve(PublicResponseCachePolicy::class)->isCacheable($response->baseResponse))->toBeFalse()
        ->and($response->headers->getCookies())->toBe([]);
});

it('keeps successive edge country policies private and outside shared HTML caching', function (): void {
    config()->set('capell-insights.default_consent_region');
    config()->set('capell-insights.require_consent_for_all_regions', false);

    foreach ([['US', false], ['GB', true], ['DE', true], ['US', false]] as [$country, $required]) {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.55', 'CAPELL_EDGE_COUNTRY' => $country])
            ->getJson('/capell/insights/consent-policy');

        $response->assertOk()->assertExactJson(['consent_required' => $required]);
        expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store')
            ->and(resolve(PublicResponseCachePolicy::class)->isCacheable($response->baseResponse))->toBeFalse()
            ->and($response->headers->getCookies())->toBe([]);
    }
});
