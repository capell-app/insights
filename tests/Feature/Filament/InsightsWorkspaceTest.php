<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Insights\Actions\BuildInsightsWorkspaceAction;
use Capell\Insights\Actions\BuildJourneyTimelineAction;
use Capell\Insights\Actions\BuildRecentJourneysQueryAction;
use Capell\Insights\Data\InsightsWindowData;
use Capell\Insights\Enums\InsightsEventType;
use Capell\Insights\Filament\Pages\InsightsPage;
use Capell\Insights\Models\InsightsEvent;
use Capell\Insights\Models\InsightsVisit;
use Capell\Insights\Settings\InsightsSettings;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-08 12:00:00');
    config()->set('capell-insights.dashboard_cache_ttl_seconds', 0);
    $this->actingAsAdmin();
    $panel = Panel::make()->id('admin')->path('admin')->default();
    Filament::registerPanel($panel);
    Filament::setCurrentPanel($panel);
    Filament::bootCurrentPanel();
    Filament::setServingStatus();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('renders one question led workspace with accessible shared filters and secondary states', function (): void {
    Site::factory()->create(['name' => 'Editorial site']);
    $page = Livewire::test(InsightsPage::class)
        ->assertOk()
        ->assertSeeInOrder(['Which audience', 'Visitors', 'Page views', 'Engagement (clicks)', 'Selected trend', 'Popular / Trending', 'Journeys / Actions', 'Acquisition', 'Funnel detail'])
        ->assertSee('No recorded activity')
        ->assertSee('Only consent-permitted activity')
        ->assertSee('No matching records in this section');

    $dom = new DOMDocument;
    $html = $page->html();
    expect($html)->not->toBe('');
    if ($html === '') {
        throw new RuntimeException('The workspace must render HTML.');
    }

    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//form[@*[name()="wire:submit"]="applyFilters"]'))->toHaveCount(1)
        ->and($xpath->query('//label[@for="insights-siteId"]'))->toHaveCount(1)
        ->and($xpath->query('//label[@for="insights-languageId"]'))->toHaveCount(1)
        ->and($xpath->query('//input[@type="date"]'))->toHaveCount(2)
        ->and($xpath->query('//*[@*[name()="wire:loading"] and @role="status"]'))->toHaveCount(5)
        ->and($xpath->query('//textarea[@aria-describedby="insights-funnel-help"]'))->toHaveCount(1);
});

it('projects every report through the same site language period and optional comparison', function (): void {
    $language = Language::factory()->create();
    $site = Site::factory()->create(['language_id' => $language->getKey()]);
    $foreignSite = Site::factory()->create();
    $foreignLanguage = Language::factory()->create();
    $window = new InsightsWindowData(CarbonImmutable::parse('2026-09-02')->startOfDay(), CarbonImmutable::parse('2026-09-08')->endOfDay(), (int) $site->getKey(), (int) $language->getKey());
    $visit = insightsWorkspaceVisit($site, $language, '2026-09-03 10:00:00', 'newsletter');
    insightsWorkspaceEvent($visit, '/included', '2026-09-03 10:00:00');
    insightsWorkspaceEvent($visit, '/included', '2026-09-03 10:01:00', InsightsEventType::Click);
    insightsWorkspaceEvent($visit, '/included', '2026-09-03 10:02:00', InsightsEventType::Custom, 'signup');
    $previousVisit = insightsWorkspaceVisit($site, $language, '2026-08-26 00:00:00', 'old-source');
    insightsWorkspaceEvent($previousVisit, '/previous', '2026-08-26 00:00:00');
    $foreignVisit = insightsWorkspaceVisit($foreignSite, $language, '2026-09-03 10:00:00', 'foreign-source');
    insightsWorkspaceEvent($foreignVisit, '/foreign-site', '2026-09-03 10:00:00');
    $foreignLanguageVisit = insightsWorkspaceVisit($site, $foreignLanguage, '2026-09-03 10:00:00', 'foreign-language-source');
    insightsWorkspaceEvent($foreignLanguageVisit, '/foreign-language', '2026-09-03 10:00:00');

    $report = BuildInsightsWorkspaceAction::run($window, true, 'clicks', ['signup']);
    expect($report->metrics)->toBe([
        ['label' => 'Visitors', 'value' => 1, 'previous' => 1],
        ['label' => 'Page views', 'value' => 1, 'previous' => 1],
        ['label' => 'Engagement (clicks)', 'value' => 1, 'previous' => 0],
    ])->and($report->trendChange)->toBe(1)
        ->and($report->sections['content'][0]->rows)->toBe([['/included', '1', '1', '1']])
        ->and($report->sections['content'][1]->rows)->toBe([['/included', '1', '0', '+1']])
        ->and($report->sections['journeys'][0]->rows)->toBe([['3', '/included']])
        ->and($report->sections['journeys'][1]->rows[0][0])->toBe('signup')
        ->and($report->sections['acquisition'][0]->rows[0][0])->toBe('newsletter')
        ->and($report->sections['acquisition'][0]->rows)->toHaveCount(1)
        ->and($report->sections['funnel'][0]->rows)->toBe([['signup', '1', '100%']]);

    $uncompared = BuildInsightsWorkspaceAction::run($window, false, 'page-views');
    expect($uncompared->trendChange)->toBeNull()
        ->and($uncompared->metrics[0]['previous'])->toBeNull()
        ->and($uncompared->sections['content'][1]->rows)->toBe([]);
});

it('updates shared filters without exposing visit identifiers or retaining the previous site', function (): void {
    $language = Language::factory()->create();
    $first = Site::factory()->create(['language_id' => $language->getKey()]);
    $second = Site::factory()->create();
    $visit = insightsWorkspaceVisit($first, $language, '2026-09-08 10:00:00');
    insightsWorkspaceEvent($visit, '/first-only', '2026-09-08 10:00:00');
    insightsWorkspaceEvent($visit, '/first-only', '2026-09-08 10:01:00', InsightsEventType::Custom, 'signup');
    Livewire::test(InsightsPage::class)
        ->set('siteId', (int) $first->getKey())
        ->set('languageId', (int) $language->getKey())
        ->set('compare', true)
        ->set('trendMetric', 'unique-visits')
        ->set('funnelSteps', 'signup')
        ->call('applyFilters')
        ->assertSee('/first-only')
        ->assertSee('signup')
        ->assertSee('Previous period: 0')
        ->assertDontSee($visit->uuid)
        ->set('siteId', (int) $second->getKey())
        ->assertSet('languageId', null)
        ->call('applyFilters')
        ->assertDontSee('/first-only')
        ->assertSee('No recorded activity');
});

it('keeps windowed journeys within matching event dates and language', function (): void {
    $site = Site::factory()->create();
    $language = Language::factory()->create();
    $otherLanguage = Language::factory()->create();
    $visit = insightsWorkspaceVisit($site, $language, '2026-09-02 10:00:00');
    insightsWorkspaceEvent($visit, '/outside-period', '2026-09-01 10:00:00');
    insightsWorkspaceEvent($visit, '/selected', '2026-09-03 10:00:00');
    insightsWorkspaceEvent($visit, '/other-language', '2026-09-03 11:00:00')->update(['language_id' => $otherLanguage->getKey()]);
    $visit->update(['last_seen_at' => '2026-09-09 10:00:00']);
    $window = new InsightsWindowData(CarbonImmutable::parse('2026-09-02'), CarbonImmutable::parse('2026-09-08')->endOfDay(), (int) $site->getKey(), (int) $language->getKey());

    expect(BuildJourneyTimelineAction::run($visit, $window)->pluck('path')->all())->toBe(['/selected'])
        ->and(BuildRecentJourneysQueryAction::run(5, $window)->first())->toMatchArray(['steps' => 1, 'last_path' => '/selected'])
        ->and(BuildJourneyTimelineAction::run($visit))->toHaveCount(3);
});

it('shows tracking disabled consent and stale states without discarding historical reports', function (): void {
    $site = Site::factory()->create();
    $language = Language::factory()->create();
    $visit = insightsWorkspaceVisit($site, $language, '2026-09-03 10:00:00');
    insightsWorkspaceEvent($visit, '/historical', '2026-09-03 10:00:00');
    $settings = resolve(InsightsSettings::class);
    $settings->enabled = false;
    $settings->require_consent_for_all_regions = true;
    $settings->save();
    config(['capell-insights.enabled' => false, 'capell-insights.require_consent_for_all_regions' => true]);
    Livewire::test(InsightsPage::class)
        ->assertSee('Tracking is disabled')
        ->assertSee('Consent is required in every region')
        ->assertSee('No events were recorded in the last 24 hours')
        ->assertSee('Latest recorded event in this period: 2026-09-03 10:00:00')
        ->assertSee('/historical');
});

it('rejects invalid windows and forged languages before producing reports', function (string $property, mixed $value): void {
    Site::factory()->create();
    Livewire::test(InsightsPage::class)
        ->set($property, $value)
        ->call('applyFilters')
        ->assertHasErrors([$property])
        ->assertSee('Review the filters above');
})->with([
    ['startsOn', 'invalid'],
    ['startsOn', '2020-01-01'],
    ['endsOn', '2026-09-01'],
    ['endsOn', '2026-09-09'],
    ['languageId', 999999],
    ['trendMetric', 'unknown'],
    ['funnelSteps', implode("\n", range(1, 11))],
]);

it('denies anonymous and unpermitted page requests including subsequent updates', function (): void {
    $site = Site::factory()->create();
    $page = Livewire::test(InsightsPage::class);
    $this->actingAsUser();
    expect(InsightsPage::canAccess())->toBeFalse();
    $page->set('siteId', (int) $site->getKey())->assertForbidden();
    Livewire::test(InsightsPage::class)->assertForbidden();
    auth()->logout();
    expect(InsightsPage::canAccess())->toBeFalse();
    Livewire::test(InsightsPage::class)->assertForbidden();
});

it('denies a forged site outside the actors assignments', function (): void {
    $site = Site::factory()->create();
    Permission::findOrCreate('View:InsightsPage', 'web');
    $user = User::factory()->create();
    $user->givePermissionTo('View:InsightsPage');
    $this->actingAs($user);
    Livewire::test(InsightsPage::class)
        ->assertSee('No site is available')
        ->set('siteId', (int) $site->getKey())
        ->assertForbidden();
});

it('keeps the workspace query count bounded as visits grow', function (): void {
    $site = Site::factory()->create();
    $language = Language::factory()->create();
    $window = new InsightsWindowData(CarbonImmutable::parse('2026-09-02'), CarbonImmutable::parse('2026-09-08')->endOfDay(), (int) $site->getKey());
    for ($index = 0; $index < 6; $index++) {
        $visit = insightsWorkspaceVisit($site, $language, '2026-09-03 10:00:00');
        insightsWorkspaceEvent($visit, '/bounded-' . $index, '2026-09-03 10:00:00');
    }

    DB::enableQueryLog();
    BuildInsightsWorkspaceAction::run($window, true, 'page-views', ['signup']);
    $initialCount = count(DB::getQueryLog());
    DB::disableQueryLog();
    for ($index = 6; $index < 30; $index++) {
        $visit = insightsWorkspaceVisit($site, $language, '2026-09-03 10:00:00');
        insightsWorkspaceEvent($visit, '/bounded-' . $index, '2026-09-03 10:00:00');
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $report = BuildInsightsWorkspaceAction::run($window, true, 'page-views', ['signup']);
    $grownCount = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($grownCount)->toBe($initialCount)->toBeLessThanOrEqual(25)
        ->and($report->sections['journeys'][0]->rows)->toHaveCount(5)
        ->and($report->sections['content'][0]->rows)->toHaveCount(5);
});

function insightsWorkspaceVisit(Site $site, Language $language, string $at, string $source = 'newsletter'): InsightsVisit
{
    return InsightsVisit::factory()->create([
        'site_id' => $site->getKey(), 'language_id' => $language->getKey(),
        'started_at' => $at, 'last_seen_at' => $at, 'utm_source' => $source,
    ]);
}

function insightsWorkspaceEvent(InsightsVisit $visit, string $path, string $at, InsightsEventType $type = InsightsEventType::PageView, ?string $name = null): InsightsEvent
{
    return InsightsEvent::factory()->create([
        'visit_id' => $visit->getKey(), 'site_id' => $visit->site_id, 'language_id' => $visit->language_id,
        'path' => $path, 'url' => 'https://example.test' . $path, 'occurred_at' => $at, 'type' => $type, 'event_name' => $name,
    ]);
}

it('reports effective tracking configuration even when saved preferences differ', function (): void {
    $site = Site::factory()->create();
    $settings = resolve(InsightsSettings::class);
    $settings->enabled = false;
    $settings->require_consent_for_all_regions = true;
    $settings->save();
    config(['capell-insights.enabled' => true, 'capell-insights.require_consent_for_all_regions' => false]);
    $window = new InsightsWindowData(CarbonImmutable::today(), CarbonImmutable::today()->endOfDay(), $site->id);
    $report = BuildInsightsWorkspaceAction::run($window, false, 'page-views');
    expect($report->trackingEnabled)->toBeTrue()
        ->and($report->consentRequiredEverywhere)->toBeFalse();
});

it('evaluates historical freshness at the end of the selected window', function (): void {
    $site = Site::factory()->create();
    $language = Language::factory()->create();
    $visit = insightsWorkspaceVisit($site, $language, '2026-08-20 23:00:00');
    insightsWorkspaceEvent($visit, '/historical-fresh', '2026-08-20 23:00:00');
    $window = new InsightsWindowData(CarbonImmutable::parse('2026-08-20'), CarbonImmutable::parse('2026-08-20')->endOfDay(), $site->id);
    expect(BuildInsightsWorkspaceAction::run($window, false, 'page-views')->stale)->toBeFalse();
});

it('escapes recorded paths and keeps report tables labelled', function (): void {
    $site = Site::factory()->create();
    $language = Language::factory()->create();
    $visit = insightsWorkspaceVisit($site, $language, '2026-09-08 10:00:00');
    insightsWorkspaceEvent($visit, '<script>alert(1)</script>', '2026-09-08 10:00:00');
    Livewire::test(InsightsPage::class)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('<caption class="sr-only">', false)
        ->assertSee('scope="col"', false);
});
