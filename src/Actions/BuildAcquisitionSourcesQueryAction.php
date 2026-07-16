<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Insights\Data\InsightsWindowData;
use Capell\Insights\Models\InsightsVisit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Collection<int, array{source: string, medium: string, campaign: string, referrer: string, visits: int}> run(InsightsWindowData $window, ?int $limit = 5)
 */
final class BuildAcquisitionSourcesQueryAction
{
    use AsFake;
    use AsObject;

    /**
     * @return Collection<int, array{source: string, medium: string, campaign: string, referrer: string, visits: int}>
     */
    public function handle(InsightsWindowData $window, ?int $limit = 5): Collection
    {
        /** @var Collection<int, array{source: string, medium: string, campaign: string, referrer: string, visits: int}> $sources */
        $sources = RememberInsightsDashboardAggregateAction::run(
            RememberInsightsDashboardAggregateAction::windowKey('acquisition-sources', $window, [
                'limit' => $limit,
            ]),
            fn (): Collection => $this->buildSources($window, $limit),
        );

        return $sources;
    }

    /**
     * @return Collection<int, array{source: string, medium: string, campaign: string, referrer: string, visits: int}>
     */
    private function buildSources(InsightsWindowData $window, ?int $limit = 5): Collection
    {
        $summaries = InsightsVisit::query()
            ->select([
                'utm_source',
                'utm_medium',
                'utm_campaign',
                'referrer_url',
                DB::raw('COUNT(*) as visits'),
            ])
            ->whereBetween('started_at', [$window->startsAt, $window->endsAt])
            ->when($window->siteId !== null, fn (Builder $builder): Builder => $builder->where('site_id', $window->siteId))
            ->when($window->languageId !== null, fn (Builder $builder): Builder => $builder->where('language_id', $window->languageId))
            ->groupBy('utm_source', 'utm_medium', 'utm_campaign', 'referrer_url')
            ->get()
            ->map(fn (InsightsVisit $visit): array => $this->sourceSummary($visit))
            ->groupBy('key')
            ->map(fn (Collection $group): array => $this->mergeGroupedSource($group))
            ->sortBy([
                ['visits', 'desc'],
                ['source', 'asc'],
                ['medium', 'asc'],
                ['campaign', 'asc'],
            ])
            ->values();

        if ($limit === null) {
            return $summaries;
        }

        return $summaries->take($limit)->values();
    }

    /**
     * @return array{key: string, source: string, medium: string, campaign: string, referrer: string, visits: int}
     */
    private function sourceSummary(InsightsVisit $visit): array
    {
        $referrerHost = $this->hostForUrl($visit->referrer_url);
        $source = $this->displayValue($visit->utm_source) ?? $referrerHost ?? (string) __('capell-insights::widgets.direct');
        $medium = $this->displayValue($visit->utm_medium)
            ?? ($referrerHost !== null ? (string) __('capell-insights::widgets.referral') : (string) __('capell-insights::widgets.direct'));
        $campaign = $this->displayValue($visit->utm_campaign) ?? '-';
        $referrer = $referrerHost ?? '-';

        return [
            'key' => implode('|', [$source, $medium, $campaign, $referrer]),
            'source' => $source,
            'medium' => $medium,
            'campaign' => $campaign,
            'referrer' => $referrer,
            'visits' => (int) $visit->visits,
        ];
    }

    /**
     * @param  Collection<int, array{key: string, source: string, medium: string, campaign: string, referrer: string, visits: int}>  $group
     * @return array{source: string, medium: string, campaign: string, referrer: string, visits: int}
     */
    private function mergeGroupedSource(Collection $group): array
    {
        /** @var array{key: string, source: string, medium: string, campaign: string, referrer: string, visits: int} $first */
        $first = $group->first();

        return [
            'source' => $first['source'],
            'medium' => $first['medium'],
            'campaign' => $first['campaign'],
            'referrer' => $first['referrer'],
            'visits' => $group->reduce(
                static fn (int $visits, array $summary): int => $visits + $summary['visits'],
                0,
            ),
        ];
    }

    private function displayValue(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function hostForUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && trim($host) !== '' ? strtolower($host) : null;
    }
}
