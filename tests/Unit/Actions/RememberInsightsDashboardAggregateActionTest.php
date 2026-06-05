<?php

declare(strict_types=1);

use Capell\Insights\Actions\RememberInsightsDashboardAggregateAction;
use Illuminate\Support\Facades\Cache;

it('remembers dashboard aggregate values until the insights tag is flushed', function (): void {
    Cache::setDefaultDriver('array');
    Cache::flush();

    $key = RememberInsightsDashboardAggregateAction::key('test-aggregate', [
        'window' => '2026-06-05',
        'limit' => 5,
    ]);

    $firstValue = RememberInsightsDashboardAggregateAction::run($key, fn (): array => ['value' => 1]);
    $cachedValue = RememberInsightsDashboardAggregateAction::run($key, fn (): array => ['value' => 2]);

    RememberInsightsDashboardAggregateAction::flush();

    $refreshedValue = RememberInsightsDashboardAggregateAction::run($key, fn (): array => ['value' => 3]);

    expect($firstValue)->toBe(['value' => 1])
        ->and($cachedValue)->toBe(['value' => 1])
        ->and($refreshedValue)->toBe(['value' => 3]);
});

it('bypasses dashboard aggregate caching when ttl is disabled', function (): void {
    Cache::setDefaultDriver('array');
    Cache::flush();
    config()->set('capell-insights.dashboard_cache_ttl_seconds', 0);

    $key = RememberInsightsDashboardAggregateAction::key('disabled-cache', [
        'window' => '2026-06-05',
    ]);

    $firstValue = RememberInsightsDashboardAggregateAction::run($key, fn (): array => ['value' => 1]);
    $secondValue = RememberInsightsDashboardAggregateAction::run($key, fn (): array => ['value' => 2]);

    expect($firstValue)->toBe(['value' => 1])
        ->and($secondValue)->toBe(['value' => 2]);
});
