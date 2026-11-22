<?php

declare(strict_types=1);

use Capell\Core\Data\Rendering\RenderHookContext;
use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Models\Site;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Capell\Insights\Actions\HashInsightsVisitorValueAction;
use Capell\Insights\Actions\RecordInsightsEventsAction;
use Capell\Insights\Data\InsightsEventData;
use Capell\Insights\Enums\InsightsConsentRegion;
use Capell\Insights\Filament\Pages\InsightsPage;
use Capell\Insights\Models\InsightsEvent;
use Capell\Insights\Settings\InsightsSettings;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(CreatesAdminUser::class);

function authorityTrackerOutput(): string
{
    /** @var RenderHookRegistry<RenderHookContext> $registry */
    $registry = resolve(RenderHookRegistry::class);

    return $registry->renderAll(RenderHookLocation::BodyEnd);
}

/**
 * @return Collection<int, InsightsEvent>
 */
function authorityPageView(Site $site, string $url = 'https://example.test/pricing'): Collection
{
    $request = Request::create('/capell/insights/events', 'POST');
    $request->attributes->set('site', $site);

    return RecordInsightsEventsAction::run(
        visitUuid: null,
        events: [['data' => InsightsEventData::from(['type' => 'page_view', 'url' => $url, 'path' => parse_url($url, PHP_URL_PATH)]), 'occurred_at' => null]],
        request: $request,
        consentRegion: InsightsConsentRegion::OutsideUkOrEurope,
    );
}

it('renders no tracker when Insights is turned off in saved settings, whatever config says', function (): void {
    config()->set('capell-insights.enabled', true);
    $this->saveInsightsSettings(['enabled' => false]);

    expect(authorityTrackerOutput())->not->toContain('data-capell-insights-tracker');
});

it('sends the saved tracking toggles and policy version to the tracker', function (): void {
    config()->set('capell-insights.track_page_views', true);
    config()->set('capell-insights.track_clicks', true);
    config()->set('capell-insights.automatic_click_tracking', true);
    config()->set('capell-insights.policy_version', '1.0');
    $this->saveInsightsSettings([
        'track_page_views' => false,
        'track_clicks' => false,
        'automatic_click_tracking' => false,
        'policy_version' => '2026-11',
        'ignored_selectors' => ['.no-track'],
    ]);

    expect(authorityTrackerOutput())
        ->toContain('"trackPageViews":false')
        ->toContain('"trackClicks":false')
        ->toContain('"automaticClickTracking":false')
        ->toContain('"policyVersion":"2026-11"')
        ->toContain('"ignoredSelectors":[".no-track"]');
});

it('skips the tracker on a saved ignored path that config does not list', function (): void {
    config()->set('capell-insights.ignored_paths', []);
    $this->saveInsightsSettings(['ignored_paths' => ['/account*']]);
    app()->instance('request', Request::create('/account/orders', 'GET'));

    expect(authorityTrackerOutput())->not->toContain('data-capell-insights-tracker');
});

it('records nothing when saved settings turn Insights off', function (): void {
    config()->set('capell-insights.enabled', true);
    $this->saveInsightsSettings(['enabled' => false]);

    expect(authorityPageView(Site::factory()->create()))->toBeEmpty();
});

it('records nothing for a page on a saved ignored path', function (): void {
    config()->set('capell-insights.ignored_paths', []);
    $this->saveInsightsSettings(['ignored_paths' => ['/pricing*']]);

    expect(authorityPageView(Site::factory()->create()))->toBeEmpty();
});

it('stores no visitor identifier when saved settings turn hashing off', function (): void {
    config()->set('capell-insights.hash_visitor_data', true);

    $this->saveInsightsSettings(['hash_visitor_data' => false]);
    expect(HashInsightsVisitorValueAction::run('203.0.113.7', null))->toBeNull();

    $this->saveInsightsSettings(['hash_visitor_data' => true]);
    expect(HashInsightsVisitorValueAction::run('203.0.113.7', null))->toBeString()->not->toBe('203.0.113.7');
});

it('invalidates every site page cache when Insights settings are saved', function (): void {
    $sites = Site::factory()->count(2)->create();
    Event::fake([FrontendSurrogateKeysInvalidated::class]);

    $this->saveInsightsSettings(['track_clicks' => false]);

    Event::assertDispatched(
        FrontendSurrogateKeysInvalidated::class,
        fn (FrontendSurrogateKeysInvalidated $event): bool => $sites->every(
            fn (Site $site): bool => in_array('site-' . $site->id, $event->surrogateKeys, true),
        ),
    );
});

it('copies the deployment config into saved settings and drops the controls that stay in config', function (): void {
    config()->set('capell-insights.enabled', false);
    config()->set('capell-insights.track_clicks', false);
    config()->set('capell-insights.policy_version', '3.1');
    config()->set('capell-insights.ignored_paths', ['/members*']);

    $migration = require dirname(__DIR__, 3) . '/database/settings/2026_11_18_000001_make_insights_settings_authoritative.php';
    $migration->up();
    app()->forgetInstance(InsightsSettings::class);

    $settings = resolve(InsightsSettings::class);
    $stored = DB::table('settings')->where('group', 'insights')->pluck('name')->all();

    expect($settings->enabled)->toBeFalse()
        ->and($settings->track_clicks)->toBeFalse()
        ->and($settings->policy_version)->toBe('3.1')
        ->and($settings->ignored_paths)->toBe(['/members*'])
        ->and($stored)->not->toContain('route_prefix')
        ->and($stored)->not->toContain('hash_salt')
        ->and($stored)->not->toContain('track_forms');
});

it('downloads the digest CSV for the selected site from the Insights page', function (): void {
    CarbonImmutable::setTestNow('2026-09-08 12:00:00');
    $this->actingAsAdmin();
    $panel = Panel::make()->id('admin')->path('admin')->default();
    Filament::registerPanel($panel);
    Filament::setCurrentPanel($panel);
    Filament::bootCurrentPanel();
    Filament::setServingStatus();
    $site = Site::factory()->create();

    Livewire::test(InsightsPage::class)
        ->set('siteId', (int) $site->getKey())
        ->call('applyFilters')
        ->callAction('exportDigestCsv')
        ->assertFileDownloaded('insights-digest-2026-09-02-to-2026-09-08.csv', contentType: 'text/csv; charset=UTF-8');
});
