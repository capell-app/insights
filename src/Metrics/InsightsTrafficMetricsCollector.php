<?php

declare(strict_types=1);

namespace Capell\Insights\Metrics;

use Capell\Core\Contracts\Metrics\CollectsDailyMetrics;
use Capell\Core\Data\Metrics\MetricCollectionResultData;
use Capell\Core\Data\Metrics\MetricDefinitionData;
use Capell\Core\Data\Metrics\MetricGovernanceData;
use Capell\Core\Data\Metrics\MetricIdentityData;
use Capell\Core\Data\Metrics\MetricRepresentationData;
use Capell\Core\Data\Metrics\MetricSampleData;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Data\Metrics\MetricSemanticsData;
use Capell\Core\Data\Metrics\MetricValueData;
use Capell\Core\Enums\Metrics\MetricAggregation;
use Capell\Core\Enums\Metrics\MetricBackfillPolicy;
use Capell\Core\Enums\Metrics\MetricCollectionStatus;
use Capell\Core\Enums\Metrics\MetricGapPolicy;
use Capell\Core\Enums\Metrics\MetricScopeType;
use Capell\Core\Enums\Metrics\MetricSemantic;
use Capell\Core\Enums\Metrics\MetricSensitivity;
use Capell\Core\Enums\Metrics\MetricSource;
use Capell\Core\Enums\Metrics\MetricValueType;
use Capell\Core\Enums\Metrics\MetricVisibility;
use Capell\Core\Enums\MetricUnitEnum;
use Capell\Core\Models\Site;
use Capell\Insights\Actions\RebuildInsightsDailyRollupsAction;
use Capell\Insights\Actions\ResolveInsightsSiteTimezoneAction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

final class InsightsTrafficMetricsCollector implements CollectsDailyMetrics
{
    private const string Owner = 'capell-app/insights';

    private const string Collector = 'traffic';

    /** @return list<MetricDefinitionData> */
    public function definitions(): array
    {
        return [
            $this->definition(
                'traffic.page_views',
                __('capell-insights::metrics.page_views'),
                __('capell-insights::metrics.page_views_description'),
            ),
            $this->definition(
                'traffic.unique_visits',
                __('capell-insights::metrics.unique_visits'),
                __('capell-insights::metrics.unique_visits_description'),
            ),
            $this->definition(
                'traffic.unique_visitors',
                __('capell-insights::metrics.unique_visitors'),
                __('capell-insights::metrics.unique_visitors_description'),
            ),
        ];
    }

    /**
     * Reads the site-level daily visitor rows, which are built per site in that site's timezone.
     * A scope is supported when it starts at local midnight in the timezone its rows were built in.
     *
     * @param  list<MetricScopeData>  $scopes
     */
    public function collect(string $day, array $scopes): MetricCollectionResultData
    {
        $keys = array_map(static fn (MetricScopeData $scope): string => $scope->key(), $scopes);
        $siteIds = [];

        foreach ($scopes as $scope) {
            $siteId = $this->supportedSiteId($scope);

            if ($siteId === false) {
                return $this->unsupported($day);
            }

            $siteIds[$scope->key()] = $siteId;
        }

        if ($scopes === [] || count(array_unique($keys)) !== count($keys)) {
            return $this->unsupported($day);
        }

        $definitions = collect($this->definitions())->keyBy(
            static fn (MetricDefinitionData $definition): string => $definition->identity->metricKey,
        );
        $samples = [];
        $checksum = [];
        $watermarks = [];

        foreach ($scopes as $scope) {
            $siteId = $siteIds[$scope->key()];
            $rows = DB::table(RebuildInsightsDailyRollupsAction::dailyVisitorsTable())
                ->whereDate('day', $day)
                ->when($siteId !== null, static fn (Builder $query): Builder => $query->where('site_id', $siteId))
                ->get(['timezone', 'unique_visitors', 'visits', 'page_views', 'updated_at']);

            if ($rows->contains(static fn (object $row): bool => $row->timezone !== $scope->timezone)) {
                return $this->unsupported($day);
            }

            $values = [
                'traffic.page_views' => $this->total($rows, 'page_views'),
                'traffic.unique_visits' => $this->total($rows, 'visits'),
                'traffic.unique_visitors' => $this->total($rows, 'unique_visitors'),
            ];

            foreach ($values as $metric => $value) {
                /** @var MetricDefinitionData $definition */
                $definition = $definitions->get($metric);
                $samples[] = new MetricSampleData(
                    identity: $definition->identity,
                    definitionHash: $definition->semanticHash(),
                    day: $day,
                    scope: $scope,
                    representation: $definition->representation,
                    value: MetricValueData::integer($value),
                );
            }

            $checksum[] = [$scope->key(), $values];
            $latest = $rows->max('updated_at');
            $watermarks[] = is_string($latest) ? $latest : 'empty';
        }

        $sourceWatermark = 'insights-daily-visitors:' . $day . ':' . max($watermarks);

        return new MetricCollectionResultData(
            status: MetricCollectionStatus::Complete,
            day: $day,
            coveredScopes: $scopes,
            samples: $samples,
            sourceWatermark: $sourceWatermark,
            sourceChecksum: hash('sha256', json_encode([
                'values' => $checksum,
                'source_watermark' => $sourceWatermark,
            ], JSON_THROW_ON_ERROR)),
            reason: null,
        );
    }

    /**
     * The site id a scope reads (null for every site), or false when the scope cannot be served.
     */
    private function supportedSiteId(MetricScopeData $scope): int|false|null
    {
        if ($scope->dayStartsAt !== '00:00:00') {
            return false;
        }

        if ($scope->type === MetricScopeType::Global) {
            return null;
        }

        if ($scope->type !== MetricScopeType::Site) {
            return false;
        }

        $siteId = Site::query()->where('uuid', $scope->siteUuid)->value('id');

        if (! is_numeric($siteId)) {
            return false;
        }

        return ResolveInsightsSiteTimezoneAction::run((int) $siteId) === $scope->timezone ? (int) $siteId : false;
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     */
    private function total(Collection $rows, string $column): int
    {
        return (int) $rows->sum(static fn (stdClass $row): int => is_numeric($row->{$column} ?? null) ? (int) $row->{$column} : 0);
    }

    private function unsupported(string $day): MetricCollectionResultData
    {
        return new MetricCollectionResultData(
            status: MetricCollectionStatus::Unsupported,
            day: $day,
            coveredScopes: [],
            samples: [],
            sourceWatermark: null,
            sourceChecksum: null,
            reason: 'Insights traffic supports unique global or site scopes that start at midnight in the timezone Insights reports that site in.',
        );
    }

    private function definition(string $metricKey, string $label, string $description): MetricDefinitionData
    {
        return new MetricDefinitionData(
            identity: new MetricIdentityData(self::Owner, self::Collector, $metricKey),
            representation: new MetricRepresentationData(MetricUnitEnum::Count, MetricValueType::Integer),
            scopeType: MetricScopeType::Global,
            semantics: new MetricSemanticsData(
                MetricSemantic::Counter,
                MetricAggregation::Sum,
                MetricGapPolicy::Missing,
                MetricBackfillPolicy::Supported,
            ),
            governance: new MetricGovernanceData(
                MetricSource::Database,
                'insights.daily-rollups',
                MetricSensitivity::Internal,
                MetricVisibility::SiteAdmin,
            ),
            labels: ['en' => $label],
            descriptions: ['en' => $description],
        );
    }
}
