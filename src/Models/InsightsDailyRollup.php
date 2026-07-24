<?php

declare(strict_types=1);

namespace Capell\Insights\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * @property CarbonImmutable $day
 * @property int|null $site_id
 * @property int|null $language_id
 * @property int $site_scope_id
 * @property int $language_scope_id
 * @property string $type
 * @property string $path
 * @property string $path_digest
 * @property string|null $url
 * @property int $events
 * @property int $page_views
 * @property int $clicks
 * @property int $unique_visits
 * @property int $current_page_views
 */
class InsightsDailyRollup extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $guarded = [];

    #[Override]
    public function getTable(): string
    {
        $tableName = config('capell-insights.tables.daily_rollups');

        return is_string($tableName) ? $tableName : 'insights_daily_rollups';
    }

    protected static function booted(): void
    {
        static::saving(function (InsightsDailyRollup $rollup): void {
            if (DB::getDriverName() !== 'mysql' && $rollup->isDirty('path')) {
                $rollup->path_digest = hash('sha256', $rollup->path);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'day' => 'immutable_date',
        ];
    }
}
