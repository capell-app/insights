<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Insights\Data\InsightsDigestData;
use Capell\Insights\Data\InsightsWindowData;
use Lorisleiva\Actions\Concerns\AsAction;

final class BuildInsightsDigestAction
{
    use AsAction;

    /**
     * @param  list<string>  $funnelSteps
     */
    public function handle(InsightsWindowData $window, array $funnelSteps = [], int $limit = 10): InsightsDigestData
    {
        return new InsightsDigestData(
            window: $window,
            overviewStats: BuildInsightsOverviewStatsAction::run($window)->values()->all(),
            popularPages: BuildPopularPagesQueryAction::run($window, $limit)->values()->all(),
            acquisitionSources: BuildAcquisitionSourcesQueryAction::run($window, $limit)->values()->all(),
            funnel: BuildFunnelConversionReportAction::run($window, $funnelSteps, 'digest'),
        );
    }
}
