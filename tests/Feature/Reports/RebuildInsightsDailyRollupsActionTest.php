<?php

declare(strict_types=1);

use Capell\Insights\Actions\BuildPopularPagesQueryAction;
use Capell\Insights\Actions\RebuildInsightsDailyRollupsAction;
use Capell\Insights\Data\InsightsWindowData;
use Capell\Insights\Enums\InsightsEventType;
use Capell\Insights\Models\InsightsDailyRollup;
use Capell\Insights\Models\InsightsEvent;
use Capell\Insights\Models\InsightsVisit;
use Carbon\CarbonImmutable;

it('rebuilds daily path rollups from raw insights events', function (): void {
    $day = CarbonImmutable::parse('2026-06-05 09:00:00');
    $firstVisit = InsightsVisit::factory()->create();
    $secondVisit = InsightsVisit::factory()->create();

    InsightsEvent::factory()->create([
        'visit_id' => $firstVisit->getKey(),
        'type' => InsightsEventType::PageView,
        'path' => '/pricing',
        'url' => 'https://example.test/pricing',
        'occurred_at' => $day,
    ]);
    InsightsEvent::factory()->create([
        'visit_id' => $secondVisit->getKey(),
        'type' => InsightsEventType::PageView,
        'path' => '/pricing',
        'url' => 'https://example.test/pricing',
        'occurred_at' => $day->addHour(),
    ]);
    InsightsEvent::factory()->create([
        'visit_id' => $firstVisit->getKey(),
        'type' => InsightsEventType::Click,
        'path' => '/pricing',
        'url' => 'https://example.test/pricing',
        'occurred_at' => $day->addHours(2),
    ]);

    expect(RebuildInsightsDailyRollupsAction::run($day->startOfDay(), $day->endOfDay()))->toBe(2);

    $pageViewRollup = InsightsDailyRollup::query()
        ->where('day', '2026-06-05')
        ->where('type', InsightsEventType::PageView->value)
        ->where('path', '/pricing')
        ->firstOrFail();

    expect($pageViewRollup->page_views)->toBe(2)
        ->and($pageViewRollup->events)->toBe(2)
        ->and($pageViewRollup->unique_visits)->toBe(2);
});

it('uses daily rollups for day-aligned popular page windows once aggregates exist', function (): void {
    $day = CarbonImmutable::parse('2026-06-05 00:00:00');

    InsightsDailyRollup::query()->create([
        'day' => '2026-06-05',
        'site_id' => null,
        'language_id' => null,
        'site_scope_id' => 0,
        'language_scope_id' => 0,
        'type' => InsightsEventType::PageView->value,
        'path' => '/docs',
        'url' => 'https://example.test/docs',
        'events' => 10,
        'page_views' => 10,
        'clicks' => 2,
        'unique_visits' => 7,
    ]);

    $pages = BuildPopularPagesQueryAction::run(new InsightsWindowData(
        startsAt: $day,
        endsAt: $day->endOfDay(),
    ));

    expect($pages)->toHaveCount(1)
        ->and($pages->first())->toMatchArray([
            'path' => '/docs',
            'page_views' => 10,
            'unique_visits' => 7,
            'clicks' => 2,
        ]);
});
