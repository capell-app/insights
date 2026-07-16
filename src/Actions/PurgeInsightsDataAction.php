<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Insights\Models\InsightsConsent;
use Capell\Insights\Models\InsightsDailyRollup;
use Capell\Insights\Models\InsightsEvent;
use Capell\Insights\Models\InsightsVisit;
use Capell\Insights\Settings\InsightsSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static int run(?int $retentionDays = null, ?int $batchSize = null)
 */
final class PurgeInsightsDataAction
{
    use AsFake;
    use AsObject;

    public function handle(?int $retentionDays = null, ?int $batchSize = null): int
    {
        $resolvedRetentionDays = $retentionDays ?? $this->defaultRetentionDays();
        $resolvedBatchSize = $this->resolveBatchSize($batchSize);
        $cutoff = now()->subDays($resolvedRetentionDays);

        $deletedEvents = $this->deleteInBatches(
            InsightsEvent::query()->where('occurred_at', '<', $cutoff),
            $resolvedBatchSize,
        );

        $deletedConsents = $this->deleteInBatches(
            InsightsConsent::query()->where('decided_at', '<', $cutoff),
            $resolvedBatchSize,
        );

        $deletedVisits = $this->deleteInBatches(
            InsightsVisit::query()
                ->where('last_seen_at', '<', $cutoff)
                ->whereDoesntHave('events'),
            $resolvedBatchSize,
        );

        $deletedRollups = $this->deleteInBatches(
            InsightsDailyRollup::query()->where('day', '<', $cutoff->toDateString()),
            $resolvedBatchSize,
        );

        $deletedRecords = $deletedEvents + $deletedConsents + $deletedVisits + $deletedRollups;

        if ($deletedRecords > 0) {
            RememberInsightsDashboardAggregateAction::flush();
        }

        return $deletedRecords;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function deleteInBatches(Builder $query, int $batchSize): int
    {
        $deletedRecords = 0;

        do {
            /** @var list<int|string> $rawIds */
            $rawIds = (clone $query)
                ->orderBy($query->getModel()->getKeyName())
                ->limit($batchSize)
                ->pluck($query->getModel()->getKeyName())
                ->values()
                ->all();
            $ids = array_map(static fn (int|string $id): int => (int) $id, $rawIds);

            if ($ids === []) {
                break;
            }

            $deletedRows = $query->getModel()
                ->newQuery()
                ->whereKey($ids)
                ->delete();

            if (is_int($deletedRows)) {
                $deletedRecords += $deletedRows;
            }
        } while (count($ids) === $batchSize);

        return $deletedRecords;
    }

    private function defaultRetentionDays(): int
    {
        if (app()->bound(InsightsSettings::class)) {
            /** @var InsightsSettings $settings */
            $settings = resolve(InsightsSettings::class);

            return $settings->retention_days;
        }

        $retentionDays = config('capell-insights.retention_days', 365);

        return is_int($retentionDays) ? $retentionDays : 365;
    }

    private function defaultBatchSize(): int
    {
        $batchSize = config('capell-insights.purge_batch_size', 500);

        return is_int($batchSize) && $batchSize > 0 ? $batchSize : 500;
    }

    private function resolveBatchSize(?int $batchSize): int
    {
        if ($batchSize !== null && $batchSize > 0) {
            return $batchSize;
        }

        return $this->defaultBatchSize();
    }
}
