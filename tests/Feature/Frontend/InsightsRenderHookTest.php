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
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Sinnbeck\DomAssertions\Asserts\AssertElement;
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

it('renders compact mobile consent actions with their accessible controls intact', function (): void {
    $this->view('capell-insights::components.consent-banner')
        ->assertElementExists('section.capell-insights-consent-banner', function (AssertElement $banner): void {
            $banner->has('hidden')
                ->has('role', 'dialog')
                ->has('aria-modal', 'false')
                ->has('aria-labelledby', 'capell-insights-consent-title')
                ->has('aria-describedby', 'capell-insights-consent-description');

            $banner->find('.capell-insights-consent-banner__panel--compact-mobile', function (AssertElement $panel): void {
                $panel->contains('h2#capell-insights-consent-title', [
                    'class' => 'capell-insights-consent-banner__title',
                    'text' => __('capell-insights::consent.banner.title'),
                ])->contains('p#capell-insights-consent-description', [
                    'class' => 'capell-insights-consent-banner__description',
                    'text' => __('capell-insights::consent.banner.description'),
                ]);

                $panel->find('.capell-insights-consent-banner__panel--compact-mobile > .capell-insights-consent-banner__actions', function (AssertElement $actions): void {
                    $actions->contains('button', [], 3);

                    foreach (['accept' => 'accept_all', 'reject' => 'reject', 'manage' => 'manage'] as $action => $translation) {
                        $actions->find(sprintf('button[data-capell-insights-consent-action="%s"]', $action), function (AssertElement $button) use ($translation): void {
                            $button->has('type', 'button')
                                ->has('class', 'capell-insights-consent-banner__button')
                                ->containsText(__('capell-insights::consent.banner.' . $translation));
                        });
                    }

                    $actions->find('button[data-capell-insights-consent-action="manage"]', function (AssertElement $button): void {
                        $button->has('class', 'capell-insights-consent-banner__button--secondary')
                            ->has('aria-expanded', 'false')
                            ->has('aria-controls', 'capell-insights-consent-choices');
                    });
                });

                $panel->find('#capell-insights-consent-choices', function (AssertElement $choices): void {
                    $choices->has('hidden')->has('data-capell-insights-consent-choices')
                        ->contains('label input[type="checkbox"][data-capell-insights-consent-category]', [], 3)
                        ->contains('button[data-capell-insights-consent-action="save"]', [
                            'type' => 'button',
                            'text' => __('capell-insights::consent.banner.save'),
                        ]);
                });
            });
        });
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

it('keeps editor state out of the complete bootstrap for public visitors', function (bool $authenticated): void {
    if ($authenticated) {
        $this->actingAs(User::factory()->create());
    }

    /** @var RenderHookRegistry<RenderHookContext> $registry */
    $registry = resolve(RenderHookRegistry::class);
    $html = $registry->renderAll(RenderHookLocation::BodyEnd);

    expect($html)->toContain('data-capell-insights-tracker')
        ->toContain('"consentRequired":true')
        ->not->toContain('CAPELL_FRAGMENT_')
        ->not->toContain('data-capell-editor')
        ->not->toContain('data-capell-model-id')
        ->not->toContain('wire:snapshot')
        ->not->toContain('signature=');
})->with(['anonymous' => false, 'non-admin' => true]);

it('keeps geography out of preparation and guarded public rendering', function (?string $configuredRegion, string $country, bool $prepare, bool $forceConsent, bool $visible = true, string $requestPath = '/', bool $signed = false): void {
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

    expect($queries)->toBe([]);
    $queries = [];

    $html = $guard->guard($renderContext, function () use ($guard): string {
        expect($guard->isActive())->toBeTrue();

        return resolve(RegisterInsightsTrackerHook::class)->render(new RenderHookContext(RenderHookLocation::BodyEnd->value, null));
    });

    if ($visible) {
        expect($html)->toContain('data-capell-insights-tracker')
            ->toContain('"consentRequired":true')
            ->not->toContain($ip);
    } else {
        expect($html)->toBe('');
    }

    expect($queries)->toBe([]);
})->with([
    'configured UK region' => ['uk_or_europe', 'US', true, false],
    'configured outside region' => ['outside_uk_or_europe', 'GB', true, false],
    'UK visitor' => [null, 'GB', true, false],
    'US visitor' => [null, 'US', true, false],
    'global requirement' => [null, 'US', true, true],
    'direct render' => [null, 'US', false, false],
    'invalid configuration' => ['invalid', 'US', false, false],
    'unknown country' => [null, '', true, false],
    'configured direct render' => ['outside_uk_or_europe', 'GB', false, false],
    'ignored admin route' => [null, 'US', true, false, false, '/admin/pages'],
    'signed beacon mode' => [null, 'US', true, false, false, '/', true],
]);

it('starts with strict consent even when the configured region is outside the UK and Europe', function (): void {
    config()->set('capell-insights.default_consent_region', 'outside_uk_or_europe');

    /** @var RenderHookRegistry<RenderHookContext> $registry */
    $registry = resolve(RenderHookRegistry::class);

    $output = $registry->renderAll(RenderHookLocation::BodyEnd);

    expect($output)->toContain('"consentRequired":true');
});

it('renders identical shared HTML for different edge visitor countries', function (): void {
    config()->set('capell-insights.default_consent_region');
    $geoip = Mockery::mock(GeoIP::class);
    $geoip->shouldReceive('config')->with('cache', 'none')->andReturn('none');
    $geoip->shouldNotReceive('getService');
    app()->instance('geoip', $geoip);

    /** @var RenderHookRegistry<RenderHookContext> $registry */
    $registry = resolve(RenderHookRegistry::class);
    $baseline = null;

    foreach (['US', 'GB', 'DE'] as $country) {
        $request = Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.55', 'CAPELL_EDGE_COUNTRY' => $country]);
        app()->instance('request', $request);
        $html = $registry->renderAll(RenderHookLocation::BodyEnd);
        $baseline ??= $html;

        expect($html)->toBe($baseline)
            ->toContain('"consentRequired":true')
            ->toContain(route('capell-insights.consent-policy', [], false));
    }
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

it('publishes the same consent expiry used by the event recorder', function (mixed $configured, ?int $expected): void {
    config()->set('capell-insights.consent_expires_days', $configured);
    /** @var RenderHookRegistry<RenderHookContext> $registry */
    $registry = resolve(RenderHookRegistry::class);
    $output = $registry->renderAll(RenderHookLocation::BodyEnd);
    if ($output === '') {
        throw new UnexpectedValueException('The rendered tracker must not be empty.');
    }

    $dom = new DOMDocument;
    $dom->loadHTML($output, LIBXML_NOERROR | LIBXML_NOWARNING);

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//script[@data-capell-insights-tracker]');
    if ($nodes === false || ! ($tracker = $nodes->item(0)) instanceof DOMNode) {
        throw new UnexpectedValueException('The rendered tracker configuration must exist.');
    }

    $config = json_decode($tracker->textContent, true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($config) || ! array_key_exists('consentExpiresDays', $config)) {
        throw new UnexpectedValueException('The tracker must publish consent expiry.');
    }

    expect($config['consentExpiresDays'])->toBe($expected);
})->with([
    'normal' => [180, 180], 'numeric string' => ['30', 30],
    'disabled null' => [null, null], 'disabled false' => [false, null],
    'invalid' => ['invalid', null], 'zero' => [0, null], 'negative' => [-1, null],
]);

it('publishes an explicit opt-in restoration barrier for custom consent interfaces', function (mixed $configured, bool $expected): void {
    config()->set('capell-insights.consent_restoration_required', $configured);
    /** @var RenderHookRegistry<RenderHookContext> $registry */
    $registry = resolve(RenderHookRegistry::class);
    $output = $registry->renderAll(RenderHookLocation::BodyEnd);
    if ($output === '') {
        throw new UnexpectedValueException('The restoration barrier must render its tracker configuration.');
    }
    $dom = new DOMDocument;
    $dom->loadHTML($output, LIBXML_NOERROR | LIBXML_NOWARNING);
    $nodes = (new DOMXPath($dom))->query('//script[@data-capell-insights-tracker]');
    if ($nodes === false || ! ($tracker = $nodes->item(0)) instanceof DOMNode) {
        throw new UnexpectedValueException('The rendered tracker configuration must exist.');
    }
    $config = json_decode($tracker->textContent, true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($config) || ! array_key_exists('consentRestorationRequired', $config)) {
        throw new UnexpectedValueException('The tracker must publish its restoration barrier.');
    }
    expect($config['consentRestorationRequired'])->toBe($expected);
})->with(['opted in' => [true, true], 'standalone' => [false, false], 'string is not enabled' => ['true', false], 'unset' => [null, false]]);
