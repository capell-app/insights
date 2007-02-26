<?php

declare(strict_types=1);

use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\RenderHookContext;
use Capell\Frontend\Enums\FrontendRenderAudience;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Events\FrontendRenderPreparing;
use Capell\Frontend\Support\Render\PublicViewQueryGuard;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Capell\Insights\Support\RenderHooks\RegisterInsightsTrackerHook;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Torann\GeoIP\GeoIP;

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

it('renders the public tracker without querying the configured database cache store', function (): void {
    config([
        'cache.default' => 'database',
        'cache.stores.database.table' => 'cache',
    ]);

    Schema::dropIfExists('cache');
    Schema::create('cache', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });

    $queries = [];
    DB::listen(static function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $html = view('capell-insights::tracker')->render();

    expect($queries)->toBe([])
        ->and($html)
        ->toContain('data-capell-insights-tracker')
        ->toContain("document.querySelector('[data-capell-insights-tracker]')");
});

it('prepares consent before guarded tracker rendering with a real database cache', function (?string $configuredRegion, string $country, bool $prepare, bool $forceConsent, bool $consentRequired, int $preparationQueries, bool $visible = true, string $requestPath = '/', bool $signed = false): void {
    config([
        'cache.default' => 'database',
        'cache.stores.database.connection' => 'sqlite',
        'cache.stores.database.table' => 'insights_geoip_cache',
        'capell-insights.default_consent_region' => $configuredRegion,
        'capell-insights.require_consent_for_all_regions' => $forceConsent,
        'capell-insights.require_signed_beacons' => $signed,
        'capell-insights.ignored_paths' => ['/admin*'],
        'capell-frontend.public_view_query_guard.enabled' => true,
        'capell-frontend.public_view_query_guard.mode' => 'exception',
        'capell-frontend.public_view_query_guard.ignored_connections' => [],
    ]);
    Cache::purge('database');
    Schema::connection('sqlite')->create('insights_geoip_cache', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });

    expect(Cache::store('database')->getStore())->toBeInstanceOf(DatabaseStore::class);

    $ip = '192.0.2.55';
    Cache::put($ip, ['ip' => $ip, 'iso_code' => $country], 60);
    app()->instance('request', Request::create($requestPath, 'GET', server: ['REMOTE_ADDR' => $ip]));
    // Real GeoIP cache reads, but no service exists that could make a network request.
    app()->instance('geoip', new GeoIP([
        'cache' => 'all',
        'cache_tags' => [],
        'services' => [],
        'service' => null,
    ], resolve(CacheManager::class)));

    $context = resolve(FrontendContextReader::class);
    $context->setFrontendData('renderAudience', FrontendRenderAudience::Public);

    $renderContext = new FrontendRenderContextData(null, null, null, null, null);
    $guard = resolve(PublicViewQueryGuard::class);
    $queries = [];
    DB::listen(static function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    if ($prepare) {
        Event::dispatch(new FrontendRenderPreparing($context, $renderContext));
    }

    expect($queries)->toHaveCount($preparationQueries);
    $queries = [];

    $html = $guard->guard($renderContext, function () use ($guard): string {
        expect($guard->isActive())->toBeTrue();

        return resolve(RegisterInsightsTrackerHook::class)->render(new RenderHookContext(RenderHookLocation::BodyEnd->value, null));
    });

    if ($visible) {
        expect($html)->toContain('data-capell-insights-tracker')
            ->toContain('"consentRequired":' . ($consentRequired ? 'true' : 'false'))
            ->not->toContain($ip);
    } else {
        expect($html)->toBe('');
    }

    expect($queries)->toBe([]);
})->with([
    'configured region bypasses GeoIP' => ['uk_or_europe', 'US', true, false, true, 0],
    'configured region overrides cached country' => ['outside_uk_or_europe', 'GB', true, false, false, 0],
    'prepared UK location requires consent' => [null, 'GB', true, false, true, 1],
    'prepared US location preserves optional consent' => [null, 'US', true, false, false, 1],
    'global requirement overrides prepared US location' => [null, 'US', true, true, true, 1],
    'unprepared fallback requires consent without GeoIP' => [null, 'US', false, false, true, 0],
    'invalid configured region fails closed without preparation' => ['invalid', 'US', false, false, true, 0],
    'unknown prepared country requires consent' => [null, '', true, false, true, 1],
    'configured fallback preserves precedence without preparation' => ['outside_uk_or_europe', 'GB', false, false, false, 0],
    'ignored admin route skips preparation and tracker' => [null, 'US', true, false, true, 0, false, '/admin/pages'],
    'signed beacon mode skips preparation and tracker' => [null, 'US', true, false, true, 0, false, '/', true],
]);

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
