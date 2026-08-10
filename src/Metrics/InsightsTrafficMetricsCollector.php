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
use Illuminate\Support\Facades\DB;

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
        ];
    }

    /**
     * @param  list<MetricScopeData>  $scopes
     */
    public function collect(string $day, array $scopes): MetricCollectionResultData
    {
        $globalScopes = array_values(array_filter(
            $scopes,
            static fn (MetricScopeData $scope): bool => $scope->type === MetricScopeType::Global
                && $scope->timezone === 'UTC'
                && $scope->dayStartsAt === '00:00:00',
        ));

        if ($globalScopes === []
            || count($globalScopes) !== count($scopes)
            || count(array_unique(array_map(static fn (MetricScopeData $scope): string => $scope->key(), $globalScopes))) !== count($globalScopes)) {
            return new MetricCollectionResultData(
                status: MetricCollectionStatus::Unsupported,
                day: $day,
                coveredScopes: [],
                samples: [],
                sourceWatermark: null,
                sourceChecksum: null,
                reason: 'Insights traffic supports unique global UTC midnight scopes only.',
            );
        }

        $row = DB::table($this->tableName())
            ->whereDate('day', $day)
            ->selectRaw('COALESCE(SUM(page_views), 0) AS page_views')
            ->selectRaw('COALESCE(SUM(unique_visits), 0) AS unique_visits')
            ->selectRaw('MAX(updated_at) AS source_watermark')
            ->first();

        $values = [
            'traffic.page_views' => (int) ($row->page_views ?? 0),
            'traffic.unique_visits' => (int) ($row->unique_visits ?? 0),
        ];
        $definitions = collect($this->definitions())->keyBy(
            static fn (MetricDefinitionData $definition): string => $definition->identity->metricKey,
        );
        $samples = [];

        foreach ($globalScopes as $scope) {
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
        }

        $sourceWatermark = 'insights-daily-rollups:' . $day . ':' . ((string) ($row->source_watermark ?? 'empty'));

        return new MetricCollectionResultData(
            status: MetricCollectionStatus::Complete,
            day: $day,
            coveredScopes: $globalScopes,
            samples: $samples,
            sourceWatermark: $sourceWatermark,
            sourceChecksum: hash('sha256', json_encode([
                'values' => $values,
                'source_watermark' => $sourceWatermark,
            ], JSON_THROW_ON_ERROR)),
            reason: null,
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

    private function tableName(): string
    {
        $tableName = config('capell-insights.tables.daily_rollups', 'insights_daily_rollups');

        return is_string($tableName) && $tableName !== '' ? $tableName : 'insights_daily_rollups';
    }
}
