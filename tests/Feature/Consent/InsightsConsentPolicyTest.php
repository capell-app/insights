<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

it('resolves the existing regional policy through a stateless private GET', function (?string $region, bool $forceConsent, bool $required): void {
    config()->set('capell-insights.default_consent_region', $region);
    config()->set('capell-insights.require_consent_for_all_regions', $forceConsent);

    app()->instance('geoip', new class
    {
        public function getLocation(?string $ip = null): never
        {
            throw new RuntimeException('Geography unavailable');
        }
    });

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
