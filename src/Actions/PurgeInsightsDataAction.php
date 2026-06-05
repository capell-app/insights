<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Insights\Models\InsightsConsent;
use Capell\Insights\Models\InsightsEvent;
use Capell\Insights\Models\InsightsVisit;
use Capell\Insights\Settings\InsightsSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

final class PurgeInsightsDataAction
{
    use AsAction;

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

        return $deletedEvents + $deletedConsents + $deletedVisits;
    }

    /** @param Builder<Model> $query */
    private function deleteInBatches(Builder $query, int $batchSize): int
    {
        $deletedRecords = 0;

        do {
            /** @var list<int> $ids */
            $ids = (clone $query)
                ->orderBy($query->getModel()->getKeyName())
                ->limit($batchSize)
                ->pluck($query->getModel()->getKeyName())
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if ($ids === []) {
                break;
            }

            $deletedRecords += $query->getModel()
                ->newQuery()
                ->whereKey($ids)
                ->delete();
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
