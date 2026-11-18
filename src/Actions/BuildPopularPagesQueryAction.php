<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Insights\Data\InsightsWindowData;
use Capell\Insights\Enums\InsightsEventType;
use Capell\Insights\Models\InsightsDailyRollup;
use Capell\Insights\Models\InsightsEvent;
use Capell\Insights\Models\InsightsVisit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Collection<int, array{path: string, url: string, page_views: int, unique_visits: int, clicks: int}> run(InsightsWindowData $window, ?int $limit = null)
 */
final class BuildPopularPagesQueryAction
{
    use AsFake;
    use AsObject;

    /**
     * @return Collection<int, array{path: string, url: string, page_views: int, unique_visits: int, clicks: int}>
     */
    public function handle(InsightsWindowData $window, ?int $limit = null): Collection
    {
        /** @var Collection<int, array{path: string, url: string, page_views: int, unique_visits: int, clicks: int}> $popularPages */
        $popularPages = RememberInsightsDashboardAggregateAction::run(
            RememberInsightsDashboardAggregateAction::windowKey('popular-pages', $window, [
                'limit' => $limit,
            ]),
            fn (): Collection => $this->buildPopularPages($window, $limit),
        );

        return $popularPages;
    }

    /**
     * @return Collection<int, array{path: string, url: string, page_views: int, unique_visits: int, clicks: int}>
     */
    private function buildPopularPages(InsightsWindowData $window, ?int $limit = null): Collection
    {
        if ($this->shouldUseDailyRollups($window)) {
            return $this->buildPopularPagesFromRollups($window, $limit);
        }

        $clicksByPath = $this->clicksByPath($window);

        $events = (new InsightsEvent)->getTable();
        $visits = (new InsightsVisit)->getTable();

        // Sampled visits stand for 1/sample_rate real visits, so counts are scaled by weight.
        $query = InsightsEvent::query()
            ->leftJoin($visits . ' as insights_weighted_visits', 'insights_weighted_visits.id', '=', $events . '.visit_id')
            ->select([
                $events . '.path',
                DB::raw('MIN(url) as url'),
                DB::raw('ROUND(SUM(COALESCE(insights_weighted_visits.sample_weight, 1))) as page_views'),
                DB::raw('ROUND(COUNT(DISTINCT insights_weighted_visits.id) * AVG(COALESCE(insights_weighted_visits.sample_weight, 1))) as unique_visits'),
            ])
            ->where($events . '.type', InsightsEventType::PageView)
            ->whereBetween($events . '.occurred_at', [$window->startsAt, $window->endsAt])
            ->when($window->siteId !== null, fn (Builder $builder): Builder => $builder->where($events . '.site_id', $window->siteId))
            ->when($window->languageId !== null, fn (Builder $builder): Builder => $builder->where($events . '.language_id', $window->languageId))
            ->groupBy($events . '.path')
            ->orderByDesc('page_views')
            ->orderBy('path');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query
            ->get()
            ->map(fn (InsightsEvent $event): array => [
                'path' => (string) $event->path,
                'url' => (string) $event->url,
                'page_views' => (int) $event->page_views,
                'unique_visits' => (int) $event->unique_visits,
                'clicks' => $clicksByPath[$event->path] ?? 0,
            ])
            ->values();
    }

    /**
     * @return Collection<int, array{path: string, url: string, page_views: int, unique_visits: int, clicks: int}>
     */
    private function buildPopularPagesFromRollups(InsightsWindowData $window, ?int $limit = null): Collection
    {
        $query = InsightsDailyRollup::query()
            ->select([
                'path',
                DB::raw('MIN(url) as url'),
                DB::raw('SUM(page_views) as page_views'),
                DB::raw('SUM(unique_visits) as unique_visits'),
                DB::raw('SUM(clicks) as clicks'),
            ])
            ->whereDate('day', '>=', $window->startsAt->toDateString())
            ->whereDate('day', '<=', $window->endsAt->toDateString())
            ->when($window->siteId !== null, fn (Builder $builder): Builder => $builder->where('site_id', $window->siteId))
            ->when($window->languageId !== null, fn (Builder $builder): Builder => $builder->where('language_id', $window->languageId))
            ->groupBy('path')
            ->orderByDesc('page_views')
            ->orderBy('path');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query
            ->get()
            ->map(fn (InsightsDailyRollup $rollup): array => [
                'path' => $rollup->path,
                'url' => (string) $rollup->url,
                'page_views' => $rollup->page_views,
                'unique_visits' => $rollup->unique_visits,
                'clicks' => $rollup->clicks,
            ])
            ->values();
    }

    /**
     * @return array<string, int>
     */
    private function clicksByPath(InsightsWindowData $window): array
    {
        $events = (new InsightsEvent)->getTable();
        $visits = (new InsightsVisit)->getTable();

        return InsightsEvent::query()
            ->leftJoin($visits . ' as insights_weighted_visits', 'insights_weighted_visits.id', '=', $events . '.visit_id')
            ->select([
                $events . '.path',
                DB::raw('SUM(COALESCE(insights_weighted_visits.sample_weight, 1)) as clicks'),
            ])
            ->where($events . '.type', InsightsEventType::Click)
            ->whereBetween($events . '.occurred_at', [$window->startsAt, $window->endsAt])
            ->when($window->siteId !== null, fn (Builder $builder): Builder => $builder->where($events . '.site_id', $window->siteId))
            ->when($window->languageId !== null, fn (Builder $builder): Builder => $builder->where($events . '.language_id', $window->languageId))
            ->groupBy($events . '.path')
            ->pluck('clicks', 'path')
            ->mapWithKeys(fn (mixed $clicks, string $path): array => [$path => is_numeric($clicks) ? (int) round((float) $clicks) : 0])
            ->all();
    }

    private function shouldUseDailyRollups(InsightsWindowData $window): bool
    {
        $isDailyWindow = $window->startsAt->isStartOfDay()
            && $window->endsAt->isEndOfDay()
            && ! $window->endsAt->lessThan($window->startsAt);

        if (! $isDailyWindow) {
            return false;
        }

        return InsightsDailyRollup::query()
            ->whereDate('day', '>=', $window->startsAt->toDateString())
            ->whereDate('day', '<=', $window->endsAt->toDateString())
            ->when($window->siteId !== null, fn (Builder $builder): Builder => $builder->where('site_id', $window->siteId))
            ->when($window->languageId !== null, fn (Builder $builder): Builder => $builder->where('language_id', $window->languageId))
            ->exists();
    }
}
