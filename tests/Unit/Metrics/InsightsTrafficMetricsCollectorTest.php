<?php

declare(strict_types=1);

use Capell\Core\Data\Metrics\MetricSampleData;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Enums\Metrics\MetricCollectionStatus;
use Capell\Insights\Metrics\InsightsTrafficMetricsCollector;
use Capell\Insights\Models\InsightsDailyRollup;

it('defines the traffic metrics as supported global counters', function (): void {
    $definitions = resolve(InsightsTrafficMetricsCollector::class)->definitions();

    expect($definitions)->toHaveCount(2)
        ->and($definitions[0]->identity->key())->toBe('capell-app/insights:traffic:traffic.page_views')
        ->and($definitions[1]->identity->key())->toBe('capell-app/insights:traffic:traffic.unique_visits');
});

it('sums daily insights rollups site-wide', function (): void {
    $day = '2026-07-21';
    $timestamps = ['2026-07-22 00:00:00', '2026-07-22 00:01:00'];

    foreach ([
        ['page_views' => 3, 'unique_visits' => 2],
        ['page_views' => 4, 'unique_visits' => 1],
        ['page_views' => 99, 'unique_visits' => 99, 'day' => '2026-07-20'],
    ] as $index => $values) {
        InsightsDailyRollup::query()->create([
            'day' => $values['day'] ?? $day,
            'site_id' => null,
            'language_id' => null,
            'site_scope_id' => $index + 1,
            'language_scope_id' => $index + 1,
            'type' => 'page',
            'path' => '/metrics-' . $index,
            'url' => 'https://example.test/metrics-' . $index,
            'events' => 0,
            'page_views' => $values['page_views'],
            'clicks' => 0,
            'unique_visits' => $values['unique_visits'],
            'created_at' => $timestamps[$index] ?? '2026-07-22 00:02:00',
            'updated_at' => $timestamps[$index] ?? '2026-07-22 00:02:00',
        ]);
    }

    expect(InsightsDailyRollup::query()->count())->toBe(3)
        ->and(InsightsDailyRollup::query()->whereDate('day', $day)->count())->toBe(2);

    $result = resolve(InsightsTrafficMetricsCollector::class)->collect($day, [MetricScopeData::global('UTC')]);
    $samples = collect($result->samples)->keyBy(static fn ($sample): string => $sample->identity->metricKey);
    $pageViews = $samples->get('traffic.page_views');
    $uniqueVisits = $samples->get('traffic.unique_visits');

    expect($pageViews)->toBeInstanceOf(MetricSampleData::class)
        ->and($uniqueVisits)->toBeInstanceOf(MetricSampleData::class);
    throw_unless($pageViews instanceof MetricSampleData, RuntimeException::class, 'Expected page-view metric sample.');
    throw_unless($uniqueVisits instanceof MetricSampleData, RuntimeException::class, 'Expected unique-visit metric sample.');

    expect($result->status)->toBe(MetricCollectionStatus::Complete)
        ->and($pageViews->value->integer)->toBe(7)
        ->and($uniqueVisits->value->integer)->toBe(3);
});

it('rejects unsupported traffic scopes', function (): void {
    $result = resolve(InsightsTrafficMetricsCollector::class)->collect(
        '2026-07-21',
        [MetricScopeData::global('Europe/London')],
    );

    expect($result->status)->toBe(MetricCollectionStatus::Unsupported)
        ->and($result->reason)->toBe('Insights traffic supports unique global UTC midnight scopes only.');
});
