<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Insights\Data\InsightsWindowData;
use Capell\Insights\Enums\InsightsEventType;
use Capell\Insights\Models\InsightsDailyRollup;
use Capell\Insights\Models\InsightsEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static Collection<int, array{path: string, url: string, current_page_views: int, previous_page_views: int, change: int<1, max>, change_percentage: float}> run(InsightsWindowData $window, ?int $limit = null)
 */
final class BuildTrendingPagesQueryAction
{
    use AsAction;

    /**
     * @return Collection<int, array{path: string, url: string, current_page_views: int, previous_page_views: int, change: int<1, max>, change_percentage: float}>
     */
    public function handle(InsightsWindowData $window, ?int $limit = null): Collection
    {
        /** @var Collection<int, array{path: string, url: string, current_page_views: int, previous_page_views: int, change: int<1, max>, change_percentage: float}> $trendingPages */
        $trendingPages = RememberInsightsDashboardAggregateAction::run(
            RememberInsightsDashboardAggregateAction::windowKey('trending-pages', $window, [
                'limit' => $limit,
            ]),
            fn (): Collection => $this->buildTrendingPages($window, $limit),
        );

        return $trendingPages;
    }

    /**
     * @return Collection<int, array{path: string, url: string, current_page_views: int, previous_page_views: int, change: int<1, max>, change_percentage: float}>
     */
    private function buildTrendingPages(InsightsWindowData $window, ?int $limit = null): Collection
    {
        if ($this->shouldUseDailyRollups($window)) {
            return $this->buildTrendingPagesFromRollups($window, $limit);
        }

        $previousPageViews = $this->previousPageViews($window);

        $summaries = InsightsEvent::query()
            ->select([
                'path',
                DB::raw('MIN(url) as url'),
                DB::raw('COUNT(*) as current_page_views'),
            ])
            ->where('type', InsightsEventType::PageView)
            ->whereBetween('occurred_at', [$window->startsAt, $window->endsAt])
            ->when($window->siteId !== null, fn (Builder $builder): Builder => $builder->where('site_id', $window->siteId))
            ->when($window->languageId !== null, fn (Builder $builder): Builder => $builder->where('language_id', $window->languageId))
            ->groupBy('path')
            ->get()
            ->map(function (InsightsEvent $event) use ($previousPageViews): array {
                $currentPageViews = $event->current_page_views;
                $previousCount = $previousPageViews[$event->path] ?? 0;
                $change = $currentPageViews - $previousCount;

                return [
                    'path' => (string) $event->path,
                    'url' => (string) $event->url,
                    'current_page_views' => $currentPageViews,
                    'previous_page_views' => $previousCount,
                    'change' => $change,
                    'change_percentage' => $this->changePercentage($currentPageViews, $previousCount),
                ];
            })
            ->filter(fn (array $summary): bool => $summary['change'] > 0)
            ->sortBy([
                ['change', 'desc'],
                ['current_page_views', 'desc'],
                ['path', 'asc'],
            ])
            ->values();

        if ($limit === null) {
            return $summaries;
        }

        return $summaries->take($limit)->values();
    }

    /**
     * @return Collection<int, array{path: string, url: string, current_page_views: int, previous_page_views: int, change: int<1, max>, change_percentage: float}>
     */
    private function buildTrendingPagesFromRollups(InsightsWindowData $window, ?int $limit = null): Collection
    {
        $previousPageViews = $this->previousRollupPageViews($window);

        $summaries = InsightsDailyRollup::query()
            ->select([
                'path',
                DB::raw('MIN(url) as url'),
                DB::raw('SUM(page_views) as current_page_views'),
            ])
            ->whereBetween('day', [$window->startsAt->toDateString(), $window->endsAt->toDateString()])
            ->when($window->siteId !== null, fn (Builder $builder): Builder => $builder->where('site_id', $window->siteId))
            ->when($window->languageId !== null, fn (Builder $builder): Builder => $builder->where('language_id', $window->languageId))
            ->groupBy('path')
            ->get()
            ->map(function (InsightsDailyRollup $rollup) use ($previousPageViews): array {
                $currentPageViews = $rollup->current_page_views;
                $previousCount = $previousPageViews[$rollup->path] ?? 0;
                $change = $currentPageViews - $previousCount;

                return [
                    'path' => $rollup->path,
                    'url' => (string) $rollup->url,
                    'current_page_views' => $currentPageViews,
                    'previous_page_views' => $previousCount,
                    'change' => $change,
                    'change_percentage' => $this->changePercentage($currentPageViews, $previousCount),
                ];
            })
            ->filter(fn (array $summary): bool => $summary['change'] > 0)
            ->sortBy([
                ['change', 'desc'],
                ['current_page_views', 'desc'],
                ['path', 'asc'],
            ])
            ->values();

        if ($limit === null) {
            return $summaries;
        }

        return $summaries->take($limit)->values();
    }

    /**
     * @return array<string, int>
     */
    private function previousPageViews(InsightsWindowData $window): array
    {
        return InsightsEvent::query()
            ->select([
                'path',
                DB::raw('COUNT(*) as page_views'),
            ])
            ->where('type', InsightsEventType::PageView)
            ->where('occurred_at', '>=', $this->previousWindowStart($window))
            ->where('occurred_at', '<', $window->startsAt)
            ->when($window->siteId !== null, fn (Builder $builder): Builder => $builder->where('site_id', $window->siteId))
            ->when($window->languageId !== null, fn (Builder $builder): Builder => $builder->where('language_id', $window->languageId))
            ->groupBy('path')
            ->pluck('page_views', 'path')
            ->mapWithKeys(fn (mixed $pageViews, string $path): array => [$path => $this->integerValue($pageViews)])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function previousRollupPageViews(InsightsWindowData $window): array
    {
        return InsightsDailyRollup::query()
            ->select([
                'path',
                DB::raw('SUM(page_views) as page_views'),
            ])
            ->where('day', '>=', $this->previousWindowStart($window)->toDateString())
            ->where('day', '<', $window->startsAt->toDateString())
            ->when($window->siteId !== null, fn (Builder $builder): Builder => $builder->where('site_id', $window->siteId))
            ->when($window->languageId !== null, fn (Builder $builder): Builder => $builder->where('language_id', $window->languageId))
            ->groupBy('path')
            ->pluck('page_views', 'path')
            ->mapWithKeys(fn (mixed $pageViews, string $path): array => [$path => (int) $pageViews])
            ->all();
    }

    private function previousWindowStart(InsightsWindowData $window): CarbonImmutable
    {
        $seconds = max(1, (int) $window->startsAt->diffInSeconds($window->endsAt));

        return $window->startsAt->subSeconds($seconds);
    }

    private function integerValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function changePercentage(int $currentPageViews, int $previousPageViews): float
    {
        if ($previousPageViews === 0) {
            return $currentPageViews > 0 ? 100.0 : 0.0;
        }

        return round((($currentPageViews - $previousPageViews) / $previousPageViews) * 100, 1);
    }

    private function shouldUseDailyRollups(InsightsWindowData $window): bool
    {
        $isDailyWindow = $window->startsAt->isStartOfDay()
            && $window->endsAt->isEndOfDay()
            && $window->startsAt->diffInDays($window->endsAt) >= 1;

        if (! $isDailyWindow) {
            return false;
        }

        return InsightsDailyRollup::query()
            ->whereBetween('day', [$window->startsAt->toDateString(), $window->endsAt->toDateString()])
            ->when($window->siteId !== null, fn (Builder $builder): Builder => $builder->where('site_id', $window->siteId))
            ->when($window->languageId !== null, fn (Builder $builder): Builder => $builder->where('language_id', $window->languageId))
            ->exists();
    }
}
