<?php

declare(strict_types=1);

namespace Capell\Insights\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property CarbonImmutable $day
 * @property int|null $site_id
 * @property int|null $language_id
 * @property int $site_scope_id
 * @property int $language_scope_id
 * @property string $type
 * @property string $path
 * @property string|null $url
 * @property int $events
 * @property int $page_views
 * @property int $clicks
 * @property int $unique_visits
 * @property int $current_page_views
 */
class InsightsDailyRollup extends Model
{
    use HasFactory;

    protected $guarded = [];

    #[Override]
    public function getTable(): string
    {
        $tableName = config('capell-insights.tables.daily_rollups');

        return is_string($tableName) ? $tableName : 'insights_daily_rollups';
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
