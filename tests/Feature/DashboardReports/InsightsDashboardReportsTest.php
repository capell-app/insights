<?php

declare(strict_types=1);

use Capell\Core\Models\Site;
use Capell\Insights\Actions\BuildAcquisitionSourcesQueryAction;
use Capell\Insights\Actions\BuildInsightsOverviewStatsAction;
use Capell\Insights\Actions\BuildJourneyTimelineAction;
use Capell\Insights\Actions\BuildPopularPagesQueryAction;
use Capell\Insights\Actions\BuildRecentJourneysQueryAction;
use Capell\Insights\Actions\BuildTopActionsQueryAction;
use Capell\Insights\Actions\BuildTrendingPagesQueryAction;
use Capell\Insights\Data\InsightsJourneyStepData;
use Capell\Insights\Data\InsightsWindowData;
use Capell\Insights\Enums\InsightsEventType;
use Capell\Insights\Models\InsightsDailyRollup;
use Capell\Insights\Models\InsightsEvent;
use Capell\Insights\Models\InsightsVisit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert;

/**
 * @param  Collection<array-key, array{id: string, label: string, value: int}>  $stats
 */
function insightsOverviewStatValue(Collection $stats, string $id): int
{
    $stat = $stats->get($id);

    throw_unless(is_array($stat), RuntimeException::class, sprintf('Expected overview stat [%s] to exist.', $id));

    return $stat['value'];
}

it('sorts popular pages by page view count descending', function (): void {
    $window = insightsReportWindow();
    $firstVisit = InsightsVisit::factory()->create();
    $secondVisit = InsightsVisit::factory()->create();

    insightsReportEvent($firstVisit, InsightsEventType::PageView, '/popular', 'https://example.test/popular', $window->startsAt->addHour());
    insightsReportEvent($secondVisit, InsightsEventType::PageView, '/popular', 'https://example.test/popular', $window->startsAt->addHours(2));
    insightsReportEvent($firstVisit, InsightsEventType::Click, '/popular', 'https://example.test/popular', $window->startsAt->addHours(3));
    insightsReportEvent($firstVisit, InsightsEventType::PageView, '/quiet', 'https://example.test/quiet', $window->startsAt->addHours(4));
    insightsReportEvent($firstVisit, InsightsEventType::PageView, '/outside', 'https://example.test/outside', $window->startsAt->subDay());

    $summaries = BuildPopularPagesQueryAction::run($window);

    expect($summaries->pluck('path')->all())->toBe(['/popular', '/quiet'])
        ->and($summaries->first())->toMatchArray([
            'path' => '/popular',
            'url' => 'https://example.test/popular',
            'page_views' => 2,
            'unique_visits' => 2,
            'clicks' => 1,
        ]);
});

it('compares trending pages with the previous equivalent window', function (): void {
    $window = insightsReportWindow();
    $currentVisit = InsightsVisit::factory()->create();
    $previousVisit = InsightsVisit::factory()->create();

    insightsReportEvent($currentVisit, InsightsEventType::PageView, '/rising', 'https://example.test/rising', $window->startsAt->addHour());
    insightsReportEvent($currentVisit, InsightsEventType::PageView, '/rising', 'https://example.test/rising', $window->startsAt->addHours(2));
    insightsReportEvent($previousVisit, InsightsEventType::PageView, '/rising', 'https://example.test/rising', $window->startsAt->subHour());
    insightsReportEvent($currentVisit, InsightsEventType::PageView, '/new', 'https://example.test/new', $window->startsAt->addHours(3));

    $summaries = BuildTrendingPagesQueryAction::run($window);

    expect($summaries->pluck('path')->all())->toBe(['/rising', '/new']);

    expect($summaries->firstWhere('path', '/rising'))->toMatchArray([
        'current_page_views' => 2,
        'previous_page_views' => 1,
        'change' => 1,
        'change_percentage' => 100.0,
    ]);

    expect($summaries->firstWhere('path', '/new'))->toMatchArray([
        'current_page_views' => 1,
        'previous_page_views' => 0,
        'change' => 1,
        'change_percentage' => 100.0,
    ]);
});

it('builds an ordered journey timeline with seconds since previous step', function (): void {
    $visit = InsightsVisit::factory()->create();
    $startedAt = CarbonImmutable::parse('2026-04-30 10:00:00');

    insightsReportEvent($visit, InsightsEventType::Click, '/pricing', 'https://example.test/pricing', $startedAt->addSeconds(45), sequence: 2);
    insightsReportEvent($visit, InsightsEventType::PageView, '/', 'https://example.test/', $startedAt, sequence: 1);
    insightsReportEvent($visit, InsightsEventType::Custom, '/pricing', 'https://example.test/pricing', $startedAt->addSeconds(75), sequence: 3, eventName: 'signup_started');

    $steps = BuildJourneyTimelineAction::run($visit);
    $firstStep = $steps->get(0);
    $secondStep = $steps->get(1);
    $thirdStep = $steps->get(2);

    Assert::assertInstanceOf(InsightsJourneyStepData::class, $firstStep);
    Assert::assertInstanceOf(InsightsJourneyStepData::class, $secondStep);
    Assert::assertInstanceOf(InsightsJourneyStepData::class, $thirdStep);

    expect($steps->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and($firstStep->secondsSincePreviousStep)->toBeNull()
        ->and($secondStep->secondsSincePreviousStep)->toBe(45)
        ->and($thirdStep->secondsSincePreviousStep)->toBe(30)
        ->and($thirdStep->eventName)->toBe('signup_started');
});

it('returns recent journeys ordered by last seen date', function (): void {
    $olderVisit = InsightsVisit::factory()->create([
        'last_seen_at' => CarbonImmutable::parse('2026-04-20 10:00:00'),
    ]);
    $recentVisit = InsightsVisit::factory()->create([
        'last_seen_at' => CarbonImmutable::parse('2026-04-21 10:00:00'),
    ]);
    $emptyVisit = InsightsVisit::factory()->create([
        'last_seen_at' => CarbonImmutable::parse('2026-04-22 10:00:00'),
    ]);

    insightsReportEvent($olderVisit, InsightsEventType::PageView, '/older', 'https://example.test/older', CarbonImmutable::parse('2026-04-20 10:00:00'));
    insightsReportEvent($recentVisit, InsightsEventType::PageView, '/recent', 'https://example.test/recent', CarbonImmutable::parse('2026-04-21 10:00:00'), sequence: 1);
    insightsReportEvent($recentVisit, InsightsEventType::Click, '/recent/contact', 'https://example.test/recent/contact', CarbonImmutable::parse('2026-04-21 10:01:00'), sequence: 2);

    $journeys = BuildRecentJourneysQueryAction::run();

    expect($journeys->pluck('visit')->all())->toBe([$recentVisit->uuid, $olderVisit->uuid])
        ->and($journeys->pluck('visit')->all())->not->toContain($emptyVisit->uuid)
        ->and($journeys->first())->toMatchArray([
            'visit' => $recentVisit->uuid,
            'steps' => 2,
            'last_path' => '/recent/contact',
        ]);
});

it('excludes recent journeys from another site when the window is site scoped', function (): void {
    Site::factory()->create(['id' => 1]);
    Site::factory()->create(['id' => 2]);

    $ownSiteVisit = InsightsVisit::factory()->create([
        'site_id' => 1,
        'last_seen_at' => CarbonImmutable::parse('2026-04-21 10:00:00'),
    ]);
    $otherSiteVisit = InsightsVisit::factory()->create([
        'site_id' => 2,
        'last_seen_at' => CarbonImmutable::parse('2026-04-22 10:00:00'),
    ]);

    insightsReportEvent($ownSiteVisit, InsightsEventType::PageView, '/own', 'https://example.test/own', CarbonImmutable::parse('2026-04-21 10:00:00'));
    insightsReportEvent($otherSiteVisit, InsightsEventType::PageView, '/other', 'https://example.test/other', CarbonImmutable::parse('2026-04-22 10:00:00'));

    $window = new InsightsWindowData(
        startsAt: CarbonImmutable::parse('2026-04-20 00:00:00'),
        endsAt: CarbonImmutable::parse('2026-04-27 00:00:00'),
        siteId: 1,
    );

    $journeys = BuildRecentJourneysQueryAction::run(window: $window);

    expect($journeys->pluck('visit')->all())
        ->toBe([$ownSiteVisit->uuid])
        ->not->toContain($otherSiteVisit->uuid);
});

it('groups top actions in the current window and excludes page views', function (): void {
    $window = insightsReportWindow();
    $visit = InsightsVisit::factory()->create();

    insightsReportEvent($visit, InsightsEventType::Custom, '/pricing', 'https://example.test/pricing', $window->startsAt->addHour(), eventName: 'signup_started');
    insightsReportEvent($visit, InsightsEventType::Custom, '/pricing', 'https://example.test/pricing', $window->startsAt->addHours(2), eventName: 'signup_started');
    insightsReportEvent($visit, InsightsEventType::Click, '/pricing', 'https://example.test/pricing', $window->startsAt->addHours(3), label: 'Pricing CTA', location: 'hero');
    insightsReportEvent($visit, InsightsEventType::PageView, '/pricing', 'https://example.test/pricing', $window->startsAt->addHours(4));
    insightsReportEvent($visit, InsightsEventType::Custom, '/outside', 'https://example.test/outside', $window->startsAt->subDay(), eventName: 'outside_window');

    $actions = BuildTopActionsQueryAction::run($window);

    expect($actions->pluck('action')->all())->toBe(['signup_started', 'Pricing CTA'])
        ->and($actions->first())->toMatchArray([
            'action' => 'signup_started',
            'event_name' => 'signup_started',
            'events' => 2,
        ])
        ->and($actions->last())->toMatchArray([
            'action' => 'Pricing CTA',
            'label' => 'Pricing CTA',
            'location' => 'hero',
            'events' => 1,
        ]);
});

it('builds overview stats without double counting visits across pages', function (): void {
    $window = insightsReportWindow();
    $firstVisit = InsightsVisit::factory()->create();
    $secondVisit = InsightsVisit::factory()->create();

    insightsReportEvent($firstVisit, InsightsEventType::PageView, '/first', 'https://example.test/first', $window->startsAt->addHour());
    insightsReportEvent($firstVisit, InsightsEventType::PageView, '/second', 'https://example.test/second', $window->startsAt->addHours(2));
    insightsReportEvent($firstVisit, InsightsEventType::Click, '/unviewed-click', 'https://example.test/unviewed-click', $window->startsAt->addHours(3));
    insightsReportEvent($secondVisit, InsightsEventType::PageView, '/third', 'https://example.test/third', $window->startsAt->addHours(4));
    insightsReportEvent($secondVisit, InsightsEventType::Click, '/third', 'https://example.test/third', $window->startsAt->subDay());

    $stats = BuildInsightsOverviewStatsAction::run($window)->keyBy('id');

    expect(insightsOverviewStatValue($stats, 'page-views'))->toBe(3)
        ->and(insightsOverviewStatValue($stats, 'unique-visits'))->toBe(2)
        ->and(insightsOverviewStatValue($stats, 'clicks'))->toBe(1);
});

it('uses daily rollups for completed days in long overview windows', function (): void {
    $window = new InsightsWindowData(
        startsAt: CarbonImmutable::parse('2026-02-01 12:00:00'),
        endsAt: CarbonImmutable::parse('2026-04-12 12:00:00'),
    );
    $visit = InsightsVisit::factory()->create();

    insightsReportEvent($visit, InsightsEventType::PageView, '/start-boundary', 'https://example.test/start-boundary', $window->startsAt->addHour());
    insightsReportEvent($visit, InsightsEventType::PageView, '/before-rollup-coverage', 'https://example.test/before-rollup-coverage', CarbonImmutable::parse('2026-03-01 10:00:00'));
    insightsReportEvent($visit, InsightsEventType::PageView, '/rolled-up', 'https://example.test/rolled-up', CarbonImmutable::parse('2026-04-05 10:00:00'));
    insightsReportEvent($visit, InsightsEventType::Click, '/end-boundary', 'https://example.test/end-boundary', $window->endsAt->subHour());

    InsightsDailyRollup::query()->create([
        'day' => '2026-04-05',
        'site_scope_id' => 0,
        'language_scope_id' => 0,
        'type' => InsightsEventType::PageView->value,
        'path' => '/rolled-up',
        'url' => 'https://example.test/rolled-up',
        'events' => 8,
        'page_views' => 8,
        'clicks' => 0,
        'unique_visits' => 5,
    ]);

    $stats = BuildInsightsOverviewStatsAction::run($window)->keyBy('id');

    expect(insightsOverviewStatValue($stats, 'page-views'))->toBe(10)
        ->and(insightsOverviewStatValue($stats, 'unique-visits'))->toBe(1)
        ->and(insightsOverviewStatValue($stats, 'clicks'))->toBe(1);
});

it('groups acquisition sources by campaign then referrer and direct traffic', function (): void {
    $window = insightsReportWindow();

    InsightsVisit::factory()->create([
        'started_at' => $window->startsAt->addHour(),
        'utm_source' => 'newsletter',
        'utm_medium' => 'email',
        'utm_campaign' => 'spring',
        'referrer_url' => 'https://mail.example.test/campaign',
    ]);
    InsightsVisit::factory()->create([
        'started_at' => $window->startsAt->addHours(2),
        'utm_source' => 'newsletter',
        'utm_medium' => 'email',
        'utm_campaign' => 'spring',
        'referrer_url' => 'https://mail.example.test/other-path',
    ]);
    InsightsVisit::factory()->create([
        'started_at' => $window->startsAt->addHours(3),
        'referrer_url' => 'https://search.example.test/results?q=capell',
    ]);
    InsightsVisit::factory()->create([
        'started_at' => $window->startsAt->addHours(4),
        'referrer_url' => null,
    ]);
    InsightsVisit::factory()->create([
        'started_at' => $window->startsAt->subDay(),
        'utm_source' => 'outside',
    ]);

    $sources = BuildAcquisitionSourcesQueryAction::run($window);

    expect($sources->pluck('source')->all())->toBe([
        'newsletter',
        'Direct',
        'search.example.test',
    ]);

    expect($sources->firstWhere('source', 'newsletter'))->toMatchArray([
        'medium' => 'email',
        'campaign' => 'spring',
        'referrer' => 'mail.example.test',
        'visits' => 2,
    ]);

    expect($sources->firstWhere('source', 'search.example.test'))->toMatchArray([
        'medium' => 'Referral',
        'campaign' => '-',
        'referrer' => 'search.example.test',
        'visits' => 1,
    ]);
});

function insightsReportWindow(): InsightsWindowData
{
    return new InsightsWindowData(
        startsAt: CarbonImmutable::parse('2026-04-20 00:00:00'),
        endsAt: CarbonImmutable::parse('2026-04-27 00:00:00'),
    );
}

function insightsReportEvent(
    InsightsVisit $visit,
    InsightsEventType $type,
    string $path,
    string $url,
    CarbonImmutable $occurredAt,
    int $sequence = 1,
    ?string $eventName = null,
    ?string $label = null,
    ?string $location = null,
): InsightsEvent {
    return InsightsEvent::factory()->create([
        'visit_id' => $visit->getKey(),
        'type' => $type,
        'path' => $path,
        'url' => $url,
        'occurred_at' => $occurredAt,
        'sequence' => $sequence,
        'event_name' => $eventName,
        'label' => $label,
        'location' => $location,
    ]);
}
