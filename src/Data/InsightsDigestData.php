<?php

declare(strict_types=1);

namespace Capell\Insights\Data;

use Spatie\LaravelData\Data;

final class InsightsDigestData extends Data
{
    /**
     * @param  list<array{id: string, label: string, value: int}>  $overviewStats
     * @param  list<array{path: string, url: string, page_views: int, unique_visits: int, clicks: int}>  $popularPages
     * @param  list<array{source: string, medium: string, campaign: string, referrer: string, visits: int}>  $acquisitionSources
     * @param  array{name: string, visitors: int, steps: list<array{name: string, visitors: int, conversion_rate: float}>}  $funnel
     */
    public function __construct(
        public readonly InsightsWindowData $window,
        public readonly array $overviewStats,
        public readonly array $popularPages,
        public readonly array $acquisitionSources,
        public readonly array $funnel,
    ) {}
}
