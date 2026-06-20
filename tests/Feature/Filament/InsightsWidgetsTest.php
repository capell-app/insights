<?php

declare(strict_types=1);

use Capell\Admin\Contracts\DashboardSettingsContributor;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Insights\Enums\InsightsEventType;
use Capell\Insights\Filament\Settings\Contributors\InsightsDashboardSettingsContributor;
use Capell\Insights\Filament\Widgets\AcquisitionSourcesFilamentWidget;
use Capell\Insights\Filament\Widgets\LiveInsightsStatsFilamentWidget;
use Capell\Insights\Filament\Widgets\PopularPagesFilamentWidget;
use Capell\Insights\Filament\Widgets\RecentJourneysFilamentWidget;
use Capell\Insights\Filament\Widgets\TopActionsFilamentWidget;
use Capell\Insights\Filament\Widgets\TrendingPagesFilamentWidget;
use Capell\Insights\Models\InsightsEvent;
use Capell\Insights\Models\InsightsVisit;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Livewire\Livewire;

it('exposes insights dashboard settings keys with translated labels', function (): void {
    $entries = (new InsightsDashboardSettingsContributor)->settingsKeys();

    expect(collect($entries)->pluck('key')->all())->toBe([
        'insights_overview',
        'insights_popular_pages',
        'insights_trending_pages',
        'insights_live_stats',
        'insights_recent_journeys',
        'insights_top_actions',
        'insights_acquisition_sources',
    ]);

    foreach ($entries as $entry) {
        expect($entry['label'])->toBeString()->not->toBe('')
            ->and(str_contains($entry['label'], 'capell-insights::'))->toBeFalse()
            ->and($entry['group'])->toBeString()->not->toBe('');
    }
});

it('has concrete translations for insights widget labels', function (): void {
    $translationKeys = [
        'insights_overview',
        'popular_pages',
        'trending_pages',
        'live_statistics',
        'recent_journeys',
        'top_actions',
        'acquisition_sources',
        'metric',
        'value',
        'path',
        'source',
        'medium',
        'campaign',
        'referrer',
        'visits',
        'page_views',
        'unique_visits',
        'clicks',
        'current_page_views',
        'previous_page_views',
        'change',
        'change_percentage',
        'visit',
        'steps',
        'last_path',
        'action',
        'events',
        'direct',
        'referral',
        'live_page_views',
        'live_active_visits',
        'live_top_page',
    ];

    foreach ($translationKeys as $translationKey) {
        $translated = __('capell-insights::widgets.' . $translationKey);

        expect($translated)->toBeString()->not->toBe('capell-insights::widgets.' . $translationKey);
    }
});

it('registers the insights dashboard settings contributor', function (): void {
    $contributors = collect(app()->tagged(DashboardSettingsContributor::TAG))
        ->map(fn (DashboardSettingsContributor $contributor): string => $contributor::class);

    expect($contributors)->toContain(InsightsDashboardSettingsContributor::class);

    expect(collect(CapellAdmin::getOverviewStats(false))->pluck('key')->all())
        ->toContain('insights_overview.page-views')
        ->toContain('insights_overview.unique-visits')
        ->toContain('insights_overview.clicks');
});

it('keeps insights overview stat cache scoped to the current request', function (): void {
    $now = CarbonImmutable::parse('2026-04-24 12:00:00');
    CarbonImmutable::setTestNow($now);
    config()->set('capell-insights.dashboard_cache_ttl_seconds', 0);

    try {
        $firstVisit = InsightsVisit::factory()->create(['last_seen_at' => $now]);
        InsightsEvent::factory()->create([
            'visit_id' => $firstVisit->getKey(),
            'type' => InsightsEventType::PageView,
            'path' => '/first',
            'url' => 'https://example.test/first',
            'occurred_at' => $now,
            'sequence' => 1,
        ]);

        app()->instance('request', Request::create('/admin/insights/first'));

        $firstRequestStat = collect(CapellAdmin::getOverviewStats(false))
            ->firstWhere('key', 'insights_overview.page-views');

        $secondVisit = InsightsVisit::factory()->create(['last_seen_at' => $now]);
        InsightsEvent::factory()->create([
            'visit_id' => $secondVisit->getKey(),
            'type' => InsightsEventType::PageView,
            'path' => '/second',
            'url' => 'https://example.test/second',
            'occurred_at' => $now,
            'sequence' => 1,
        ]);

        app()->instance('request', Request::create('/admin/insights/second'));

        $secondRequestStat = collect(CapellAdmin::getOverviewStats(false))
            ->firstWhere('key', 'insights_overview.page-views');

        expect($firstRequestStat?->value)->toBe('1')
            ->and($secondRequestStat?->value)->toBe('2');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('renders insights dashboard widgets', function (string $widgetClass): void {
    Livewire::test($widgetClass)->assertOk();
})->with([
    PopularPagesFilamentWidget::class,
    TrendingPagesFilamentWidget::class,
    LiveInsightsStatsFilamentWidget::class,
    RecentJourneysFilamentWidget::class,
    TopActionsFilamentWidget::class,
    AcquisitionSourcesFilamentWidget::class,
]);

it('renders trending pages with previous count column', function (): void {
    Livewire::test(TrendingPagesFilamentWidget::class)
        ->assertOk()
        ->assertSee(__('capell-insights::widgets.previous_page_views'));
});
