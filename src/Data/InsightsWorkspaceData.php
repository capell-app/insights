<?php

declare(strict_types=1);

namespace Capell\Insights\Data;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class InsightsWorkspaceData extends Data
{
    /**
     * @param  list<array{label: string, value: int, previous: int|null}>  $metrics
     * @param  array<string, list<InsightsReportTableData>>  $sections
     */
    public function __construct(
        public readonly InsightsDigestData $digest,
        public readonly array $metrics,
        public readonly array $sections,
        public readonly ?CarbonImmutable $latestEventAt,
        public readonly bool $stale,
        public readonly bool $trackingEnabled,
        public readonly bool $consentRequiredEverywhere,
        public readonly ?int $trendChange,
    ) {}
}
