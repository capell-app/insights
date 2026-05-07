<?php

declare(strict_types=1);

namespace Capell\Insights\Models;

use Capell\Insights\Data\InsightsEventMetadataData;
use Capell\Insights\Database\Factories\InsightsEventFactory;
use Capell\Insights\Enums\InsightsEventType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string|null $path
 * @property int|null $views
 * @property int|null $visitors
 * @property string|null $date
 * @property CarbonImmutable $occurred_at
 * @property int $visit_id
 * @property int $sequence
 * @property InsightsEventType $type
 * @property string|null $url
 * @property string|null $title
 * @property string|null $event_name
 * @property string|null $label
 * @property string|null $location
 * @property string|null $target_selector
 * @property int|null $viewport_x
 * @property int|null $viewport_y
 * @property int|null $document_x
 * @property int|null $document_y
 * @property InsightsEventMetadataData|null $metadata
 * @property int $page_views
 * @property int $unique_visits
 * @property int $events
 * @property int $current_page_views
 */
class InsightsEvent extends Model
{
    /** @use HasFactory<InsightsEventFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static string $factory = InsightsEventFactory::class;

    public function getTable(): string
    {
        $tableName = config('capell-insights.tables.events');

        return is_string($tableName) ? $tableName : 'insights_events';
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(InsightsVisit::class, 'visit_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => InsightsEventType::class,
            'occurred_at' => 'immutable_datetime',
            'metadata' => InsightsEventMetadataData::class,
        ];
    }
}
