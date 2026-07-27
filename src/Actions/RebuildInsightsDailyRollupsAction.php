<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Core\Enums\Database\DatabaseCapability;
use Capell\Core\Facades\CapellDatabase;
use Capell\Insights\Enums\InsightsEventType;
use Capell\Insights\Models\InsightsDailyRollup;
use Capell\Insights\Models\InsightsEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static int run(?CarbonImmutable $startsAt = null, ?CarbonImmutable $endsAt = null)
 */
final class RebuildInsightsDailyRollupsAction
{
    use AsFake;
    use AsObject;

    public function handle(?CarbonImmutable $startsAt = null, ?CarbonImmutable $endsAt = null): int
    {
        $resolvedStartsAt = ($startsAt ?? now()->subDays($this->defaultRebuildDays())->toImmutable())->startOfDay();
        $resolvedEndsAt = ($endsAt ?? now()->toImmutable())->endOfDay();

        if ($resolvedEndsAt->lessThan($resolvedStartsAt)) {
            return 0;
        }

        $rollupTable = (new InsightsDailyRollup)->getTable();
        $eventTable = (new InsightsEvent)->getTable();
        $connection = (new InsightsDailyRollup)->getConnection();
        $requiresApplicationDigest = ! CapellDatabase::for($connection)
            ->schemaDialect()
            ->supports(DatabaseCapability::HashGeneratedColumn, $connection);
        $now = now();

        $rowCount = DB::transaction(function () use ($eventTable, $now, $requiresApplicationDigest, $resolvedEndsAt, $resolvedStartsAt, $rollupTable): int {
            InsightsDailyRollup::query()
                ->whereDate('day', '>=', $resolvedStartsAt->toDateString())
                ->whereDate('day', '<=', $resolvedEndsAt->toDateString())
                ->delete();

            $rows = DB::table($eventTable)
                ->selectRaw('DATE(occurred_at) as day')
                ->selectRaw('site_id')
                ->selectRaw('language_id')
                ->selectRaw('COALESCE(site_id, 0) as site_scope_id')
                ->selectRaw('COALESCE(language_id, 0) as language_scope_id')
                ->selectRaw('type')
                ->selectRaw('path')
                ->selectRaw('MIN(url) as url')
                ->selectRaw('COUNT(*) as events')
                ->selectRaw('SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) as page_views', [InsightsEventType::PageView->value])
                ->selectRaw('SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) as clicks', [InsightsEventType::Click->value])
                ->selectRaw('COUNT(DISTINCT visit_id) as unique_visits')
                ->whereBetween('occurred_at', [$resolvedStartsAt, $resolvedEndsAt])
                ->whereNotNull('path')
                ->groupByRaw('DATE(occurred_at), site_id, language_id, type, path')
                ->get()
                ->map(function (object $row) use ($now, $requiresApplicationDigest): array {
                    $path = (string) $row->path;
                    $values = [
                        'day' => (string) $row->day,
                        'site_id' => $row->site_id === null ? null : (int) $row->site_id,
                        'language_id' => $row->language_id === null ? null : (int) $row->language_id,
                        'site_scope_id' => (int) $row->site_scope_id,
                        'language_scope_id' => (int) $row->language_scope_id,
                        'type' => (string) $row->type,
                        'path' => $path,
                        'url' => $row->url === null ? null : (string) $row->url,
                        'events' => (int) $row->events,
                        'page_views' => (int) $row->page_views,
                        'clicks' => (int) $row->clicks,
                        'unique_visits' => (int) $row->unique_visits,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if ($requiresApplicationDigest) {
                        $values['path_digest'] = hash('sha256', $path);
                    }

                    return $values;
                })
                ->all();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table($rollupTable)->insert($chunk);
            }

            return count($rows);
        });

        if ($rowCount > 0) {
            RememberInsightsDashboardAggregateAction::flush();
        }

        return $rowCount;
    }

    private function defaultRebuildDays(): int
    {
        $days = config('capell-insights.rollup_rebuild_days', 30);

        return is_int($days) && $days > 0 ? $days : 30;
    }
}
