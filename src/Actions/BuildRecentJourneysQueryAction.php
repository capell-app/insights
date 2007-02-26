<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Insights\Data\InsightsJourneyStepData;
use Capell\Insights\Data\InsightsWindowData;
use Capell\Insights\Models\InsightsVisit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildRecentJourneysQueryAction
{
    use AsFake;
    use AsObject;

    /**
     * @return Collection<int, array{id: int, visit: string, steps: int<0, max>, landing_url: string, last_path: string}>
     */
    public function handle(?int $limit = 5, ?InsightsWindowData $window = null): Collection
    {
        /** @var Collection<int, array{id: int, visit: string, steps: int<0, max>, landing_url: string, last_path: string}> $recentJourneys */
        $recentJourneys = RememberInsightsDashboardAggregateAction::run(
            RememberInsightsDashboardAggregateAction::windowKey('recent-journeys', $window, [
                'limit' => $limit,
            ]),
            fn (): Collection => $this->buildRecentJourneys($limit, $window),
        );

        return $recentJourneys;
    }

    /**
     * @return Collection<int, array{id: int, visit: string, steps: int<0, max>, landing_url: string, last_path: string}>
     */
    private function buildRecentJourneys(?int $limit = 5, ?InsightsWindowData $window = null): Collection
    {
        $query = InsightsVisit::query()
            ->whereHas('events')
            ->latest('last_seen_at');

        if ($window instanceof InsightsWindowData) {
            $query->whereHas('events', fn (Builder $query): Builder => $query
                ->whereBetween('occurred_at', [$window->startsAt, $window->endsAt])
                ->when($window->languageId !== null, fn (Builder $query): Builder => $query->where('language_id', $window->languageId)));
            $query->when($window->siteId !== null, fn (Builder $builder): Builder => $builder->where('site_id', $window->siteId));
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query
            ->get()
            ->map(function (InsightsVisit $visit) use ($window): array {
                $timeline = BuildJourneyTimelineAction::run($visit, $window);
                $lastStep = $timeline->last();

                return [
                    'id' => (int) $visit->getKey(),
                    'visit' => $visit->uuid,
                    'steps' => (int) $timeline->count(),
                    'landing_url' => (string) $visit->landing_url,
                    'last_path' => $lastStep instanceof InsightsJourneyStepData ? $lastStep->path : '',
                ];
            })
            ->values();
    }
}
