<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Core\Support\TableName;
use Capell\Insights\Enums\InsightsEventType;
use Capell\Insights\Models\InsightsDailyRollup;
use Capell\Insights\Models\InsightsEvent;
use Capell\Insights\Models\InsightsVisit;
use Capell\Insights\Support\PathDigestColumn;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Rebuilds per-path rollups and site-level daily visitor counts. Each site's days start at
 * midnight in that site's timezone, and sampled visits are scaled back up by their weight.
 *
 * @method static int run(?CarbonImmutable $startsAt = null, ?CarbonImmutable $endsAt = null)
 */
final class RebuildInsightsDailyRollupsAction
{
    use AsFake;
    use AsObject;

    public static function dailyVisitorsTable(): string
    {
        $tableName = config('capell-insights.tables.daily_visitors');

        return TableName::resolve(is_string($tableName) && $tableName !== '' ? $tableName : 'insights_daily_visitors');
    }

    public function handle(?CarbonImmutable $startsAt = null, ?CarbonImmutable $endsAt = null): int
    {
        $firstDay = ($startsAt ?? now()->subDays($this->defaultRebuildDays())->toImmutable())->toDateString();
        $lastDay = ($endsAt ?? now()->toImmutable())->toDateString();

        if ($lastDay < $firstDay) {
            return 0;
        }

        $rollupTable = (new InsightsDailyRollup)->getTable();
        $connection = (new InsightsDailyRollup)->getConnection();
        $requiresApplicationDigest = ! PathDigestColumn::isGenerated($connection, $rollupTable);
        $now = now();

        $rowCount = DB::transaction(function () use ($firstDay, $lastDay, $now, $requiresApplicationDigest, $rollupTable): int {
            InsightsDailyRollup::query()
                ->whereDate('day', '>=', $firstDay)
                ->whereDate('day', '<=', $lastDay)
                ->delete();
            DB::table(self::dailyVisitorsTable())
                ->whereDate('day', '>=', $firstDay)
                ->whereDate('day', '<=', $lastDay)
                ->delete();

            $rowCount = 0;

            foreach ($this->siteIds($firstDay, $lastDay) as $siteId) {
                $timezone = ResolveInsightsSiteTimezoneAction::run($siteId);

                for ($day = CarbonImmutable::parse($firstDay, $timezone); $day->toDateString() <= $lastDay; $day = $day->addDay()) {
                    $bounds = $this->utcBounds($day);
                    $rows = $this->pathRows($day->toDateString(), $siteId, $bounds, $now, $requiresApplicationDigest);

                    foreach (array_chunk($rows, 500) as $chunk) {
                        DB::table($rollupTable)->insert($chunk);
                    }

                    $rowCount += count($rows);
                    $this->storeDailyVisitors($day->toDateString(), $siteId, $timezone, $bounds, $now);
                }
            }

            return $rowCount;
        });

        if ($rowCount > 0) {
            RememberInsightsDashboardAggregateAction::flush();
        }

        return $rowCount;
    }

    /**
     * @return array<int, int|null>
     */
    private function siteIds(string $firstDay, string $lastDay): array
    {
        // Pad a day either side so sites far from UTC still find their local-day events.
        $paddedStartsAt = CarbonImmutable::parse($firstDay, 'UTC')->subDay();
        $paddedEndsAt = CarbonImmutable::parse($lastDay, 'UTC')->addDays(2);

        return InsightsEvent::query()
            ->whereBetween('occurred_at', [$this->storageTime($paddedStartsAt), $this->storageTime($paddedEndsAt)])
            ->whereNotNull('path')
            ->distinct()
            ->pluck('site_id')
            ->map(static fn (mixed $siteId): ?int => is_numeric($siteId) ? (int) $siteId : null)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{Carbon, Carbon}
     */
    private function utcBounds(CarbonImmutable $localDay): array
    {
        return [
            $this->storageTime($localDay->startOfDay()),
            $this->storageTime($localDay->endOfDay()),
        ];
    }

    /**
     * Event times are stored in the application timezone, so bounds are converted to it.
     */
    private function storageTime(CarbonImmutable $moment): Carbon
    {
        $timezone = config('app.timezone');

        return Carbon::instance($moment->setTimezone(is_string($timezone) && $timezone !== '' ? $timezone : 'UTC'));
    }

    /**
     * @param  array{Carbon, Carbon}  $bounds
     */
    private function scopedEvents(?int $siteId, array $bounds): Builder
    {
        $eventTable = (new InsightsEvent)->getTable();
        $visitTable = (new InsightsVisit)->getTable();

        return DB::table($eventTable . ' as events')
            ->leftJoin($visitTable . ' as visits', 'visits.id', '=', 'events.visit_id')
            ->whereBetween('events.occurred_at', $bounds)
            ->whereNotNull('events.path')
            ->when(
                $siteId === null,
                static fn (Builder $query): Builder => $query->whereNull('events.site_id'),
                static fn (Builder $query): Builder => $query->where('events.site_id', $siteId),
            );
    }

    /**
     * @param  array{Carbon, Carbon}  $bounds
     * @return array<int, array<string, mixed>>
     */
    private function pathRows(string $day, ?int $siteId, array $bounds, CarbonInterface $now, bool $requiresApplicationDigest): array
    {
        $weight = 'COALESCE(visits.sample_weight, 1)';

        return $this->scopedEvents($siteId, $bounds)
            ->selectRaw('events.language_id as language_id')
            ->selectRaw('events.type as type')
            ->selectRaw('events.path as path')
            ->selectRaw('MIN(events.url) as url')
            ->selectRaw(sprintf('SUM(%s) as events', $weight))
            ->selectRaw(sprintf('SUM(CASE WHEN events.type = ? THEN %s ELSE 0 END) as page_views', $weight), [InsightsEventType::PageView->value])
            ->selectRaw(sprintf('SUM(CASE WHEN events.type = ? THEN %s ELSE 0 END) as clicks', $weight), [InsightsEventType::Click->value])
            ->selectRaw(sprintf('COUNT(DISTINCT events.visit_id) * AVG(%s) as unique_visits', $weight))
            ->groupBy('events.language_id', 'events.type', 'events.path')
            ->get()
            ->map(function (object $row) use ($day, $now, $requiresApplicationDigest, $siteId): array {
                $path = (string) $row->path;
                $languageId = $row->language_id === null ? null : (int) $row->language_id;
                $values = [
                    'day' => $day,
                    'site_id' => $siteId,
                    'language_id' => $languageId,
                    'site_scope_id' => $siteId ?? 0,
                    'language_scope_id' => $languageId ?? 0,
                    'type' => (string) $row->type,
                    'path' => $path,
                    'url' => $row->url === null ? null : (string) $row->url,
                    'events' => $this->rounded($row->events),
                    'page_views' => $this->rounded($row->page_views),
                    'clicks' => $this->rounded($row->clicks),
                    'unique_visits' => $this->rounded($row->unique_visits),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ($requiresApplicationDigest) {
                    $values['path_digest'] = hash('sha256', $path);
                }

                return $values;
            })
            ->values()
            ->all();
    }

    /**
     * One site-level row per local day: visitors are counted once however many pages they view.
     *
     * @param  array{Carbon, Carbon}  $bounds
     */
    private function storeDailyVisitors(string $day, ?int $siteId, string $timezone, array $bounds, CarbonInterface $now): void
    {
        $visitors = DB::query()
            ->fromSub(
                $this->scopedEvents($siteId, $bounds)
                    ->whereNotNull('events.visit_id')
                    ->selectRaw('COALESCE(visits.visitor_hash, visits.uuid) as visitor_key')
                    ->selectRaw('MAX(COALESCE(visits.sample_weight, 1)) as weight')
                    ->groupByRaw('COALESCE(visits.visitor_hash, visits.uuid)'),
                'visitors',
            )
            ->selectRaw('COALESCE(SUM(weight), 0) as unique_visitors')
            ->first();

        $totals = $this->scopedEvents($siteId, $bounds)
            ->selectRaw('COUNT(DISTINCT events.visit_id) * AVG(COALESCE(visits.sample_weight, 1)) as visits')
            ->selectRaw('SUM(CASE WHEN events.type = ? THEN COALESCE(visits.sample_weight, 1) ELSE 0 END) as page_views', [InsightsEventType::PageView->value])
            ->first();

        $uniqueVisitors = $this->rounded($visitors->unique_visitors ?? 0);
        $pageViews = $this->rounded($totals->page_views ?? 0);

        if ($uniqueVisitors === 0 && $pageViews === 0) {
            return;
        }

        DB::table(self::dailyVisitorsTable())->insert([
            'day' => $day,
            'site_id' => $siteId,
            'site_scope_id' => $siteId ?? 0,
            'timezone' => $timezone,
            'unique_visitors' => $uniqueVisitors,
            'visits' => $this->rounded($totals->visits ?? 0),
            'page_views' => $pageViews,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function rounded(mixed $value): int
    {
        return is_numeric($value) ? (int) round((float) $value) : 0;
    }

    private function defaultRebuildDays(): int
    {
        $days = config('capell-insights.rollup_rebuild_days', 30);

        return is_int($days) && $days > 0 ? $days : 30;
    }
}
