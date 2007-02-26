<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Insights\Data\InsightsReportTableData;
use Capell\Insights\Data\InsightsWindowData;
use Capell\Insights\Data\InsightsWorkspaceData;
use Capell\Insights\Models\InsightsEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildInsightsWorkspaceAction
{
    use AsObject;

    /** @param list<string> $funnelSteps */
    public function handle(InsightsWindowData $window, bool $compare, string $trendMetric, array $funnelSteps = []): InsightsWorkspaceData
    {
        $digest = BuildInsightsDigestAction::run($window, $funnelSteps, 5);
        $previous = $compare ? BuildInsightsOverviewStatsAction::run(new InsightsWindowData(
            startsAt: $window->startsAt->subDays((int) $window->startsAt->startOfDay()->diffInDays($window->endsAt->startOfDay()) + 1),
            endsAt: $window->startsAt->subMicrosecond(),
            siteId: $window->siteId,
            languageId: $window->languageId,
        ))->keyBy('id') : collect();
        $current = collect($digest->overviewStats)->keyBy('id');
        $metrics = [];
        foreach (['unique-visits' => 'visitors', 'page-views' => 'page_views', 'clicks' => 'engagement'] as $id => $label) {
            $metrics[] = [
                'label' => (string) __('capell-insights::workspace.' . $label),
                'value' => $current[$id]['value'] ?? 0,
                'previous' => $compare ? ($previous[$id]['value'] ?? 0) : null,
            ];
        }

        $popular = new InsightsReportTableData(
            __('capell-insights::widgets.popular_pages'),
            [__('capell-insights::widgets.path'), __('capell-insights::widgets.page_views'), __('capell-insights::widgets.unique_visits'), __('capell-insights::widgets.clicks')],
            array_map(fn (array $row): array => [$row['path'], (string) $row['page_views'], (string) $row['unique_visits'], (string) $row['clicks']], $digest->popularPages),
        );
        $trending = new InsightsReportTableData(
            __('capell-insights::widgets.trending_pages'),
            [__('capell-insights::widgets.path'), __('capell-insights::widgets.current_page_views'), __('capell-insights::widgets.previous_page_views'), __('capell-insights::widgets.change')],
            $compare ? $this->rowsFromCollection(BuildTrendingPagesQueryAction::run($window, 5)->map(fn (array $row): array => [$row['path'], (string) $row['current_page_views'], (string) $row['previous_page_views'], '+' . $row['change']])) : [],
        );
        $journeys = new InsightsReportTableData(
            __('capell-insights::widgets.recent_journeys'),
            [__('capell-insights::widgets.steps'), __('capell-insights::widgets.last_path')],
            $this->rowsFromCollection(BuildRecentJourneysQueryAction::run(5, $window)->map(fn (array $row): array => [(string) $row['steps'], $row['last_path']])),
        );
        $actions = new InsightsReportTableData(
            __('capell-insights::widgets.top_actions'),
            [__('capell-insights::widgets.action'), __('capell-insights::widgets.events')],
            $this->rowsFromCollection(BuildTopActionsQueryAction::run($window, 5)->map(fn (array $row): array => [$row['action'], (string) $row['events']])),
        );
        $acquisition = new InsightsReportTableData(
            __('capell-insights::widgets.acquisition_sources'),
            [__('capell-insights::widgets.source'), __('capell-insights::widgets.medium'), __('capell-insights::widgets.campaign'), __('capell-insights::widgets.referrer'), __('capell-insights::widgets.visits')],
            array_map(fn (array $row): array => [$row['source'], $row['medium'], $row['campaign'], $row['referrer'], (string) $row['visits']], $digest->acquisitionSources),
        );
        $funnel = new InsightsReportTableData(
            __('capell-insights::workspace.funnel'),
            [__('capell-insights::widgets.action'), __('capell-insights::workspace.visitors'), __('capell-insights::workspace.conversion_rate')],
            array_map(fn (array $row): array => [$row['name'], (string) $row['visitors'], $row['conversion_rate'] . '%'], $digest->funnel['steps']),
        );
        $latest = InsightsEvent::query()
            ->whereBetween('occurred_at', [$window->startsAt, $window->endsAt])
            ->when($window->siteId !== null, fn (Builder $query): Builder => $query->where('site_id', $window->siteId))
            ->when($window->languageId !== null, fn (Builder $query): Builder => $query->where('language_id', $window->languageId))
            ->max('occurred_at');
        $latestEventAt = is_string($latest) ? CarbonImmutable::parse($latest) : null;

        return new InsightsWorkspaceData(
            digest: $digest,
            metrics: $metrics,
            sections: ['content' => [$popular, $trending], 'journeys' => [$journeys, $actions], 'acquisition' => [$acquisition], 'funnel' => [$funnel]],
            latestEventAt: $latestEventAt,
            stale: $latestEventAt instanceof CarbonImmutable && $latestEventAt->lessThan($window->endsAt->min(CarbonImmutable::now())->subDay()),
            trackingEnabled: (bool) config('capell-insights.enabled', true),
            consentRequiredEverywhere: (bool) config('capell-insights.require_consent_for_all_regions', false),
            trendChange: $compare ? ($current[$trendMetric]['value'] ?? 0) - ($previous[$trendMetric]['value'] ?? 0) : null,
        );
    }

    /**
     * @template TRow of list<string>
     *
     * @param  Collection<int, TRow>  $rows
     * @return list<TRow>
     */
    private function rowsFromCollection(Collection $rows): array
    {
        $list = [];
        foreach ($rows as $row) {
            $list[] = $row;
        }

        return $list;
    }
}
