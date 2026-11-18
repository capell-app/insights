<?php

declare(strict_types=1);

use Capell\Core\Data\Metrics\MetricSampleData;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Enums\Metrics\MetricCollectionStatus;
use Capell\Insights\Actions\BuildInsightsOverviewStatsAction;
use Capell\Insights\Actions\BuildPopularPagesQueryAction;
use Capell\Insights\Actions\CreateInsightsVisitAction;
use Capell\Insights\Actions\RebuildInsightsDailyRollupsAction;
use Capell\Insights\Data\InsightsWindowData;
use Capell\Insights\Enums\InsightsConsentRegion;
use Capell\Insights\Enums\InsightsEventType;
use Capell\Insights\Metrics\InsightsTrafficMetricsCollector;
use Capell\Insights\Models\InsightsDailyRollup;
use Capell\Insights\Models\InsightsEvent;
use Capell\Insights\Models\InsightsVisit;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config()->set('capell-insights.hash_salt', 'daily-uniques-test-salt');
    config()->set('capell-insights.hash_visitor_data', true);
});

/**
 * @param  list<string>  $paths
 */
function recordInsightsVisitorVisit(string $ip, string $userAgent, CarbonImmutable $at, array $paths): InsightsVisit
{
    Carbon::setTestNow($at);

    $request = Request::create('https://example.test/', 'GET', server: [
        'REMOTE_ADDR' => $ip,
        'HTTP_USER_AGENT' => $userAgent,
    ]);
    $visit = CreateInsightsVisitAction::run($request, InsightsConsentRegion::Unknown, 'https://example.test/');

    foreach ($paths as $offset => $path) {
        InsightsEvent::factory()->create([
            'visit_id' => $visit->getKey(),
            'site_id' => null,
            'language_id' => null,
            'type' => InsightsEventType::PageView,
            'path' => $path,
            'url' => 'https://example.test' . $path,
            'occurred_at' => $at->addSeconds($offset),
        ]);
    }

    Carbon::setTestNow();

    return $visit;
}

/**
 * @return array{unique_visitors: int, visits: int, page_views: int, timezone: string}|null
 */
function dailyVisitorRow(string $day): ?array
{
    $row = DB::table(RebuildInsightsDailyRollupsAction::dailyVisitorsTable())->whereDate('day', $day)->first();

    if ($row === null) {
        return null;
    }

    return [
        'unique_visitors' => is_numeric($row->unique_visitors) ? (int) $row->unique_visitors : -1,
        'visits' => is_numeric($row->visits) ? (int) $row->visits : -1,
        'page_views' => is_numeric($row->page_views) ? (int) $row->page_views : -1,
        'timezone' => is_string($row->timezone) ? $row->timezone : '',
    ];
}

it('stores one site-level unique count per day that equals the distinct visitors', function (): void {
    $day = CarbonImmutable::parse('2026-07-01 09:00:00', 'UTC');

    // The same browser opens two visits and views three pages; a second browser views one page.
    recordInsightsVisitorVisit('203.0.113.10', 'Browser A', $day, ['/', '/pricing']);
    recordInsightsVisitorVisit('203.0.113.10', 'Browser A', $day->addHours(3), ['/docs']);
    recordInsightsVisitorVisit('198.51.100.7', 'Browser B', $day->addHours(4), ['/pricing']);

    RebuildInsightsDailyRollupsAction::run($day->startOfDay(), $day->endOfDay());

    $row = dailyVisitorRow('2026-07-01');
    $pathUniqueTotal = (int) InsightsDailyRollup::query()->whereDate('day', '2026-07-01')->sum('unique_visits');

    expect($row)->toMatchArray(['unique_visitors' => 2, 'visits' => 3, 'page_views' => 4])
        ->and($pathUniqueTotal)->toBeGreaterThan(2);

    $result = resolve(InsightsTrafficMetricsCollector::class)->collect('2026-07-01', [MetricScopeData::global('UTC')]);
    $samples = collect($result->samples)->mapWithKeys(
        static fn (MetricSampleData $sample): array => [$sample->identity->metricKey => $sample->value->integer],
    );

    expect($result->status)->toBe(MetricCollectionStatus::Complete)
        ->and($samples->get('traffic.unique_visitors'))->toBe(2)
        ->and($samples->get('traffic.unique_visits'))->toBe(3)
        ->and($samples->get('traffic.page_views'))->toBe(4);

    $stats = BuildInsightsOverviewStatsAction::run(new InsightsWindowData($day->startOfDay(), $day->endOfDay()))->keyBy('id');

    expect($stats->get('unique-visits'))->toMatchArray([
        'label' => __('capell-insights::widgets.unique_visitors'),
        'value' => 2,
    ]);
});

it('counts a visitor on two days twice as visitor-days', function (): void {
    $firstDay = CarbonImmutable::parse('2026-07-01 10:00:00', 'UTC');

    $first = recordInsightsVisitorVisit('203.0.113.10', 'Browser A', $firstDay, ['/']);
    $second = recordInsightsVisitorVisit('203.0.113.10', 'Browser A', $firstDay->addDay(), ['/']);

    expect($first->visitor_hash)->not->toBeNull()
        ->and($first->visitor_hash)->not->toBe($second->visitor_hash);

    $stats = BuildInsightsOverviewStatsAction::run(new InsightsWindowData(
        $firstDay->startOfDay(),
        $firstDay->addDay()->endOfDay(),
    ))->keyBy('id');

    expect($stats->get('unique-visits'))->toMatchArray([
        'label' => __('capell-insights::widgets.visitor_days'),
        'value' => 2,
    ]);

    RebuildInsightsDailyRollupsAction::run($firstDay->startOfDay(), $firstDay->addDay()->endOfDay());

    expect(dailyVisitorRow('2026-07-01'))->toMatchArray(['unique_visitors' => 1])
        ->and(dailyVisitorRow('2026-07-02'))->toMatchArray(['unique_visitors' => 1]);
});

it('follows the site timezone for the reporting day boundary', function (): void {
    config()->set('capell-insights.timezone', 'America/New_York');

    // 02:30 UTC on 2 July is 22:30 on 1 July in New York.
    recordInsightsVisitorVisit('203.0.113.10', 'Browser A', CarbonImmutable::parse('2026-07-02 02:30:00', 'UTC'), ['/late']);

    RebuildInsightsDailyRollupsAction::run(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-02'));

    $days = InsightsDailyRollup::query()->where('path', '/late')->get()->map(
        static fn (InsightsDailyRollup $rollup): string => $rollup->day->toDateString(),
    )->all();

    expect($days)->toBe(['2026-07-01'])
        ->and(dailyVisitorRow('2026-07-01'))->toMatchArray(['timezone' => 'America/New_York'])
        ->and(dailyVisitorRow('2026-07-02'))->toBeNull();

    $collector = resolve(InsightsTrafficMetricsCollector::class);
    $local = $collector->collect('2026-07-01', [MetricScopeData::global('America/New_York')]);
    $utc = $collector->collect('2026-07-01', [MetricScopeData::global('UTC')]);

    expect($local->status)->toBe(MetricCollectionStatus::Complete)
        ->and($utc->status)->toBe(MetricCollectionStatus::Unsupported);
});

it('scales sampled traffic back up by the stored sample weight', function (): void {
    config()->set('capell-insights.ingest.sample_rate', 0.25);
    $day = CarbonImmutable::parse('2026-07-01 09:00:00', 'UTC');

    recordInsightsVisitorVisit('203.0.113.10', 'Browser A', $day, ['/', '/pricing']);
    $visit = recordInsightsVisitorVisit('198.51.100.7', 'Browser B', $day->addHour(), ['/']);

    expect((float) $visit->sample_weight)->toBe(4.0);

    $window = new InsightsWindowData($day->startOfDay(), $day->endOfDay());
    $stats = BuildInsightsOverviewStatsAction::run($window)->keyBy('id');
    $home = BuildPopularPagesQueryAction::run($window)->firstWhere('path', '/');

    expect($stats->get('page-views'))->toMatchArray(['value' => 12])
        ->and($stats->get('unique-visits'))->toMatchArray(['value' => 8])
        ->and($home)->toMatchArray(['page_views' => 8, 'unique_visits' => 8]);

    RebuildInsightsDailyRollupsAction::run($day->startOfDay(), $day->endOfDay());

    expect(dailyVisitorRow('2026-07-01'))->toMatchArray(['unique_visitors' => 8, 'page_views' => 12])
        ->and(InsightsDailyRollup::query()->where('path', '/')->sum('page_views'))->toEqual(8);
});

it('rejects metric scopes that do not start at local midnight or name an unknown site', function (): void {
    $collector = resolve(InsightsTrafficMetricsCollector::class);

    expect($collector->collect('2026-07-01', [MetricScopeData::global('UTC', '06:00:00')])->status)
        ->toBe(MetricCollectionStatus::Unsupported)
        ->and($collector->collect('2026-07-01', [MetricScopeData::site('00000000-0000-4000-8000-000000000001', 'UTC')])->status)
        ->toBe(MetricCollectionStatus::Unsupported)
        ->and($collector->collect('2026-07-01', [])->status)
        ->toBe(MetricCollectionStatus::Unsupported);
});
