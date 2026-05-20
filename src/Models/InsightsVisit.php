<?php

declare(strict_types=1);

namespace Capell\Insights\Models;

use Capell\Insights\Database\Factories\InsightsVisitFactory;
use Capell\Insights\Enums\InsightsConsentRegion;
use Capell\Insights\Enums\InsightsConsentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * @property string $uuid
 * @property string|null $ip_hash
 * @property string|null $user_agent_hash
 * @property int|null $site_id
 * @property int|null $language_id
 * @property string|null $landing_url
 * @property InsightsConsentRegion $consent_region
 * @property InsightsConsentStatus $consent_status
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $last_seen_at
 */
class InsightsVisit extends Model
{
    /** @use HasFactory<InsightsVisitFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static string $factory = InsightsVisitFactory::class;

    #[Override]
    public function getTable(): string
    {
        $tableName = config('capell-insights.tables.visits');

        return is_string($tableName) ? $tableName : 'insights_visits';
    }

    public function consents(): HasMany
    {
        return $this->hasMany(InsightsConsent::class, 'visit_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(InsightsEvent::class, 'visit_id');
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'consent_region' => InsightsConsentRegion::class,
            'consent_status' => InsightsConsentStatus::class,
            'started_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }
}
