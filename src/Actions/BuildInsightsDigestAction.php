<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Insights\Data\InsightsDigestData;
use Capell\Insights\Data\InsightsWindowData;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static InsightsDigestData run(InsightsWindowData $window, list<string> $funnelSteps = [], int $limit = 10)
 */
final class BuildInsightsDigestAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  list<string>  $funnelSteps
     */
    public function handle(InsightsWindowData $window, array $funnelSteps = [], int $limit = 10): InsightsDigestData
    {
        return new InsightsDigestData(
            window: $window,
            overviewStats: $this->listFromCollection(BuildInsightsOverviewStatsAction::run($window)),
            popularPages: $this->listFromCollection(BuildPopularPagesQueryAction::run($window, $limit)),
            acquisitionSources: $this->listFromCollection(BuildAcquisitionSourcesQueryAction::run($window, $limit)),
            funnel: BuildFunnelConversionReportAction::run($window, $funnelSteps, 'digest'),
        );
    }

    /**
     * @template TValue
     *
     * @param  Collection<int, TValue>  $collection
     * @return list<TValue>
     */
    private function listFromCollection(Collection $collection): array
    {
        $items = [];

        foreach ($collection->values() as $item) {
            $items[] = $item;
        }

        return $items;
    }
}
