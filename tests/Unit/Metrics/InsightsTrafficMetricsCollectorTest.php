<?php

declare(strict_types=1);

use Capell\Core\Data\Metrics\MetricSampleData;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Enums\Metrics\MetricCollectionStatus;
use Capell\Insights\Actions\RebuildInsightsDailyRollupsAction;
use Capell\Insights\Metrics\InsightsTrafficMetricsCollector;
use Illuminate\Support\Facades\DB;

it('defines the traffic metrics as supported global counters', function (): void {
    $definitions = resolve(InsightsTrafficMetricsCollector::class)->definitions();

    expect($definitions)->toHaveCount(3)
        ->and($definitions[0]->identity->key())->toBe('capell-app/insights:traffic:traffic.page_views')
        ->and($definitions[1]->identity->key())->toBe('capell-app/insights:traffic:traffic.unique_visits')
        ->and($definitions[2]->identity->key())->toBe('capell-app/insights:traffic:traffic.unique_visitors');
});

it('sums site-level daily visitor rows across sites', function (): void {
    $day = '2026-07-21';

    foreach ([
        ['site_scope_id' => 1, 'page_views' => 3, 'visits' => 2, 'unique_visitors' => 2, 'updated_at' => '2026-07-22 00:00:00'],
        ['site_scope_id' => 2, 'page_views' => 4, 'visits' => 1, 'unique_visitors' => 1, 'updated_at' => '2026-07-22 00:01:00'],
        ['site_scope_id' => 1, 'page_views' => 99, 'visits' => 99, 'unique_visitors' => 99, 'updated_at' => '2026-07-22 00:02:00', 'day' => '2026-07-20'],
    ] as $values) {
        DB::table(RebuildInsightsDailyRollupsAction::dailyVisitorsTable())->insert([
            'day' => $values['day'] ?? $day,
            'site_id' => null,
            'site_scope_id' => $values['site_scope_id'],
            'timezone' => 'UTC',
            'unique_visitors' => $values['unique_visitors'],
            'visits' => $values['visits'],
            'page_views' => $values['page_views'],
            'created_at' => $values['updated_at'],
            'updated_at' => $values['updated_at'],
        ]);
    }

    $result = resolve(InsightsTrafficMetricsCollector::class)->collect($day, [MetricScopeData::global('UTC')]);
    $samples = collect($result->samples)->mapWithKeys(
        static fn (MetricSampleData $sample): array => [$sample->identity->metricKey => $sample->value->integer],
    );

    expect($result->status)->toBe(MetricCollectionStatus::Complete)
        ->and($result->sourceWatermark)->toBe('insights-daily-visitors:2026-07-21:2026-07-22 00:01:00')
        ->and($samples->all())->toBe([
            'traffic.page_views' => 7,
            'traffic.unique_visits' => 3,
            'traffic.unique_visitors' => 3,
        ]);
});

it('reports an empty day as complete with zero counts', function (): void {
    $result = resolve(InsightsTrafficMetricsCollector::class)->collect('2026-07-21', [MetricScopeData::global('Europe/London')]);

    expect($result->status)->toBe(MetricCollectionStatus::Complete)
        ->and(collect($result->samples)->map(static fn (MetricSampleData $sample): ?int => $sample->value->integer)->all())->toBe([0, 0, 0]);
});

it('rejects duplicate scopes', function (): void {
    $result = resolve(InsightsTrafficMetricsCollector::class)->collect(
        '2026-07-21',
        [MetricScopeData::global('UTC'), MetricScopeData::global('UTC')],
    );

    expect($result->status)->toBe(MetricCollectionStatus::Unsupported)
        ->and($result->reason)->toContain('midnight');
});
