<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Insights\Data\InsightsWindowData;
use Capell\Insights\Enums\InsightsEventType;
use Capell\Insights\Models\InsightsDailyRollup;
use Capell\Insights\Models\InsightsEvent;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Collection<int, array{id: string, label: string, value: int}> run(InsightsWindowData $window)
 */
final class BuildInsightsOverviewStatsAction
{
    use AsFake;
    use AsObject;

    /**
     * @return Collection<int, array{id: string, label: string, value: int}>
     */
    public function handle(InsightsWindowData $window): Collection
    {
        /** @var Collection<int, array{id: string, label: string, value: int}> $stats */
        $stats = RememberInsightsDashboardAggregateAction::run(
            RememberInsightsDashboardAggregateAction::windowKey('overview-stats', $window),
            fn (): Collection => $this->buildStats($window),
        );

        return $stats;
    }

    /**
     * @return Collection<int, array{id: string, label: string, value: int}>
     */
    private function buildStats(InsightsWindowData $window): Collection
    {
        $rollupRange = $this->rollupRange($window);

        return collect([
            [
                'id' => 'page-views',
                'label' => (string) __('capell-insights::widgets.page_views'),
                'value' => $this->countEvents($window, InsightsEventType::PageView, $rollupRange),
            ],
            [
                'id' => 'unique-visits',
                'label' => (string) __('capell-insights::widgets.unique_visits'),
                'value' => $this->countUniqueVisits($window),
            ],
            [
                'id' => 'clicks',
                'label' => (string) __('capell-insights::widgets.clicks'),
                'value' => $this->countEvents($window, InsightsEventType::Click, $rollupRange),
            ],
        ]);
    }

    /** @param array{CarbonImmutable, CarbonImmutable}|null $rollupRange */
    private function countEvents(InsightsWindowData $window, InsightsEventType $type, ?array $rollupRange): int
    {
        if ($rollupRange !== null) {
            [$rollupStartsAt, $rollupEndsAt] = $rollupRange;
            $column = $type === InsightsEventType::PageView ? 'page_views' : 'clicks';
            $rollupCount = InsightsDailyRollup::query()
                ->whereDate('day', '>=', $rollupStartsAt->toDateString())
                ->whereDate('day', '<=', $rollupEndsAt->toDateString())
                ->where('type', $type->value)
                ->when($window->siteId !== null, fn (Builder $builder): Builder => $builder->where('site_id', $window->siteId))
                ->when($window->languageId !== null, fn (Builder $builder): Builder => $builder->where('language_id', $window->languageId))
                ->sum($column);

            return (int) $rollupCount + $this->rawEventCount($window, $type, $rollupStartsAt, $rollupEndsAt);
        }

        return $this->rawEventCount($window, $type);
    }

    private function rawEventCount(
        InsightsWindowData $window,
        InsightsEventType $type,
        ?CarbonImmutable $excludedStartsAt = null,
        ?CarbonImmutable $excludedEndsAt = null,
    ): int {
        return InsightsEvent::query()
            ->where('type', $type)
            ->whereBetween('occurred_at', [$window->startsAt, $window->endsAt])
            ->when($window->siteId !== null, fn (Builder $builder): Builder => $builder->where('site_id', $window->siteId))
            ->when($window->languageId !== null, fn (Builder $builder): Builder => $builder->where('language_id', $window->languageId))
            ->when(
                $excludedStartsAt instanceof CarbonImmutable && $excludedEndsAt instanceof CarbonImmutable,
                fn (Builder $builder): Builder => $builder->where(
                    fn (Builder $boundaryBuilder): Builder => $boundaryBuilder
                        ->where('occurred_at', '<', $excludedStartsAt)
                        ->orWhere('occurred_at', '>', $excludedEndsAt),
                ),
            )
            ->count();
    }

    /** @return array{CarbonImmutable, CarbonImmutable}|null */
    private function rollupRange(InsightsWindowData $window): ?array
    {
        $configuredMinimumDays = config('capell-insights.rollup_overview_min_days', 7);
        $minimumDays = is_numeric($configuredMinimumDays) ? max(1, (int) $configuredMinimumDays) : 7;

        if ($window->startsAt->diffInDays($window->endsAt) < $minimumDays) {
            return null;
        }

        $latestRollupDay = InsightsDailyRollup::query()->max('day');

        if ((! is_string($latestRollupDay) && ! $latestRollupDay instanceof DateTimeInterface) || $latestRollupDay === '') {
            return null;
        }

        $startsAtStartOfDay = $window->startsAt->startOfDay();
        $rollupStartsAt = $window->startsAt->equalTo($startsAtStartOfDay)
            ? $startsAtStartOfDay
            : $startsAtStartOfDay->addDay();
        $endsAtEndOfDay = $window->endsAt->endOfDay();
        $rollupEndsAt = $window->endsAt->equalTo($endsAtEndOfDay)
            ? $endsAtEndOfDay
            : $window->endsAt->startOfDay()->subMicrosecond();
        $latestRollupEndsAt = CarbonImmutable::parse($latestRollupDay)->endOfDay();
        $configuredRebuildDays = config('capell-insights.rollup_rebuild_days', 30);
        $rebuildDays = is_numeric($configuredRebuildDays) ? max(1, (int) $configuredRebuildDays) : 30;
        $knownRollupStartsAt = $latestRollupEndsAt->startOfDay()->subDays($rebuildDays - 1);

        if ($knownRollupStartsAt->greaterThan($rollupStartsAt)) {
            $rollupStartsAt = $knownRollupStartsAt;
        }

        if ($latestRollupEndsAt->lessThan($rollupEndsAt)) {
            $rollupEndsAt = $latestRollupEndsAt;
        }

        return $rollupStartsAt->greaterThan($rollupEndsAt)
            ? null
            : [$rollupStartsAt, $rollupEndsAt];
    }

    private function countUniqueVisits(InsightsWindowData $window): int
    {
        return InsightsEvent::query()
            ->whereBetween('occurred_at', [$window->startsAt, $window->endsAt])
            ->when($window->siteId !== null, fn (Builder $builder): Builder => $builder->where('site_id', $window->siteId))
            ->when($window->languageId !== null, fn (Builder $builder): Builder => $builder->where('language_id', $window->languageId))
            ->whereNotNull('visit_id')
            ->distinct('visit_id')
            ->count('visit_id');
    }
}
