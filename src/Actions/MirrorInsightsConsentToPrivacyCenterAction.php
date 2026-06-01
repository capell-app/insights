<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Core\Facades\CapellCore;
use Capell\Insights\Models\InsightsConsent;
use Capell\PrivacyCenter\Actions\RecordConsentAction;
use Capell\PrivacyCenter\Data\ConsentRecordData;
use Capell\PrivacyCenter\Enums\ConsentDecision;
use Capell\PrivacyCenter\Enums\CookieCategory;
use Lorisleiva\Actions\Concerns\AsAction;

final class MirrorInsightsConsentToPrivacyCenterAction
{
    use AsAction;

    /** @var array<string, string> */
    private const array CATEGORY_MAP = [
        'essential' => 'essential',
        'insights' => 'analytics',
        'marketing' => 'marketing',
        'preferences' => 'preferences',
    ];

    public function handle(InsightsConsent $consent): int
    {
        if (! $this->privacyCenterIsAvailable()) {
            return 0;
        }

        $recorded = 0;

        foreach (self::CATEGORY_MAP as $insightsCategory => $privacyCategory) {
            if ($this->recordConsent($consent, $insightsCategory, $privacyCategory)) {
                $recorded++;
            }
        }

        return $recorded;
    }

    private function privacyCenterIsAvailable(): bool
    {
        return CapellCore::isPackageInstalled('capell-app/privacy-center')
            && class_exists(RecordConsentAction::class)
            && class_exists(ConsentRecordData::class)
            && class_exists(ConsentDecision::class)
            && class_exists(CookieCategory::class);
    }

    private function recordConsent(InsightsConsent $consent, string $insightsCategory, string $privacyCategory): bool
    {
        $cookieCategoryClass = CookieCategory::class;
        $consentDecisionClass = ConsentDecision::class;
        $consentRecordDataClass = ConsentRecordData::class;
        $recordConsentActionClass = RecordConsentAction::class;

        $category = $cookieCategoryClass::tryFrom($privacyCategory);
        $decision = $consent->categories->{$insightsCategory}
            ? $consentDecisionClass::Granted
            : $consentDecisionClass::Denied;

        if ($category === null) {
            return false;
        }

        $data = new $consentRecordDataClass(
            category: $category,
            decision: $decision,
            siteId: $consent->visit?->site_id,
            policyVersion: $consent->policy_version,
            jurisdiction: $consent->consent_region->value,
            decidedAt: $consent->decided_at,
            evidence: [
                'surface' => 'insights-consent',
                'status' => $consent->status->value,
                'visit_uuid' => $consent->visit?->uuid,
            ],
            metadata: [
                'source_package' => 'capell-app/insights',
                'insights_consent_id' => $consent->getKey(),
                'insights_category' => $insightsCategory,
            ],
        );

        $recordConsentActionClass::run($data, source: $consent);

        return true;
    }
}
